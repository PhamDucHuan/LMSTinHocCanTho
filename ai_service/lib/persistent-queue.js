import crypto from 'node:crypto';
import fs from 'node:fs';
import mysql from 'mysql2/promise';

function databaseConfig() {
  return {
    host: process.env.DB_HOST || '127.0.0.1',
    port: Number.parseInt(process.env.DB_PORT || '3306', 10),
    user: process.env.DB_USER,
    password: process.env.DB_PASS || '',
    database: process.env.DB_NAME,
    charset: 'utf8mb4',
    timezone: '+07:00',
    connectionLimit: Math.max(2, Number.parseInt(process.env.AI_DB_POOL_SIZE || '5', 10)),
  };
}

function removeTemporaryInputs(payload) {
  for (const key of ['prompt_local_path', 'submission_local_path']) {
    const filePath = payload?.[key];
    if (typeof filePath !== 'string') continue;
    try {
      if (fs.existsSync(filePath)) fs.unlinkSync(filePath);
    } catch (error) {
      console.error(`Cannot remove temporary AI input ${filePath}:`, error.message);
    }
  }
}

export function startPersistentGradeWorker({ grade, concurrency = 2 }) {
  if (!process.env.DB_USER || !process.env.DB_NAME) {
    console.warn('Persistent grading worker disabled: DB_USER or DB_NAME is missing.');
    return { enabled: false, stop: async () => {} };
  }

  const pool = mysql.createPool(databaseConfig());
  const workerId = crypto.randomUUID();
  const pollMs = Math.max(500, Number.parseInt(process.env.AI_JOB_POLL_MS || '1500', 10));
  const maxAttempts = Math.max(1, Number.parseInt(process.env.AI_JOB_MAX_ATTEMPTS || '3', 10));
  let stopped = false;
  let active = 0;

  const claim = async () => {
    const token = crypto.randomUUID();
    const [result] = await pool.execute(
      `UPDATE grading_jobs
       SET status='processing', locked_at=NOW(), started_at=COALESCE(started_at, NOW()),
           worker_token=?, attempts=attempts+1
       WHERE status='queued' AND available_at <= NOW()
       ORDER BY id ASC LIMIT 1`,
      [`${workerId.slice(0, 18)}-${token.slice(0, 17)}`],
    );
    if (!result.affectedRows) return null;
    const [rows] = await pool.execute(
      'SELECT id, payload, attempts FROM grading_jobs WHERE worker_token=? AND status=\'processing\' LIMIT 1',
      [`${workerId.slice(0, 18)}-${token.slice(0, 17)}`],
    );
    const job = rows[0] || null;
    if (job) {
      await pool.execute(
        `UPDATE submissions s
         INNER JOIN grading_jobs j ON j.submission_id=s.id
         SET s.grading_status='processing', s.grading_updated_at=NOW()
         WHERE j.id=?`,
        [job.id],
      );
    }
    return job;
  };

  const processJob = async job => {
    let payload = {};
    try {
      payload = typeof job.payload === 'string' ? JSON.parse(job.payload) : job.payload;
      const result = await grade(payload);
      const [completedUpdate] = await pool.execute(
        `UPDATE grading_jobs
         SET status='completed', result_json=?, error_message=NULL, completed_at=NOW(), worker_token=NULL,
             payload=JSON_REMOVE(payload, '$.archive_password')
         WHERE id=? AND status='processing'`,
        [JSON.stringify(result), job.id],
      );
      removeTemporaryInputs(payload);
      if (!completedUpdate.affectedRows) return;
    } catch (error) {
      const retry = Number(job.attempts) < maxAttempts;
      const [failureUpdate] = await pool.execute(
        `UPDATE grading_jobs
         SET status=?, error_message=?, available_at=DATE_ADD(NOW(), INTERVAL ? SECOND),
             completed_at=?, worker_token=NULL,
             payload=IF(?, payload, JSON_REMOVE(payload, '$.archive_password'))
         WHERE id=? AND status='processing'`,
        [
          retry ? 'queued' : 'failed',
          String(error.message || error).slice(0, 4000),
          retry ? Math.min(120, 2 ** Number(job.attempts) * 5) : 0,
          retry ? null : new Date(),
          retry ? 1 : 0,
          job.id,
        ],
      );
      if (!failureUpdate.affectedRows) {
        removeTemporaryInputs(payload);
      } else if (!retry) {
        await pool.execute(
          `UPDATE submissions s
           INNER JOIN grading_jobs j ON j.submission_id=s.id
           SET s.grading_status='failed', s.grading_updated_at=NOW()
           WHERE j.id=?`,
          [job.id],
        );
      }
      if (!retry) removeTemporaryInputs(payload);
    }
  };

  const tick = async () => {
    if (stopped) return;
    while (active < concurrency) {
      let job;
      try {
        job = await claim();
      } catch (error) {
        console.error('Persistent grading queue claim failed:', error.message);
        break;
      }
      if (!job) break;
      active += 1;
      processJob(job).finally(() => { active -= 1; });
    }
  };
  const timer = setInterval(tick, pollMs);
  timer.unref();
  pool.execute(
    `UPDATE grading_jobs
     SET status='queued', worker_token=NULL, locked_at=NULL, available_at=NOW(),
         error_message='Worker bị gián đoạn; hệ thống tự khôi phục.'
     WHERE status='processing' AND locked_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)`,
  ).then(tick).catch(error => {
    console.error('Cannot recover stale grading jobs:', error.message);
  });

  return {
    enabled: true,
    workerId,
    pool,
    get active() { return active; },
    stop: async () => {
      stopped = true;
      clearInterval(timer);
      while (active > 0) await new Promise(resolve => setTimeout(resolve, 100));
      await pool.end();
    },
  };
}
