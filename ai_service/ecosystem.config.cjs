module.exports = {
  apps: [{
    name: 'lms-ai',
    script: './server.js',
    cwd: __dirname,
    instances: 1,
    exec_mode: 'fork',
    autorestart: true,
    max_memory_restart: '768M',
    kill_timeout: 30000,
    listen_timeout: 10000,
    time: true,
    error_file: './logs/service-error.log',
    out_file: './logs/service-output.log',
    merge_logs: true,
    env: {
      NODE_ENV: 'production',
    },
  }],
};
