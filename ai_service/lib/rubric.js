const qualitativePattern = /\b(đẹp|hợp lý|sáng tạo|cân đối|chuyên nghiệp|dễ đọc|thẩm mỹ|ấn tượng)\b/i;
const colorPattern = /\b(màu|mau|color|colour|font color|fill|background|nền|to màu|tô màu)\b/i;

function roundScore(value) {
  return Math.round(Number(value) * 100) / 100;
}

export function normalizeRubric({ rubric, rubricId, aiCriteria, moduleName, maxScore }) {
  if (rubric && typeof rubric === 'object' && Array.isArray(rubric.criteria)) {
    const criteria = rubric.criteria.map((criterion, index) => ({
      id: String(criterion.id || `c${index + 1}`),
      description: String(criterion.description || '').trim(),
      max_score: roundScore(criterion.max_score),
      verification_type: ['rule', 'ai_review', 'mixed'].includes(criterion.verification_type) ? criterion.verification_type : 'ai_review',
      verification: criterion.verification && typeof criterion.verification === 'object' ? criterion.verification : {},
      grading_policy: colorPattern.test(String(criterion.description || '')) ? 'ignore_color_differences' : 'standard',
    }));
    const total = roundScore(criteria.reduce((sum, criterion) => sum + criterion.max_score, 0));
    if (!criteria.length || criteria.some(criterion => !criterion.description || criterion.max_score <= 0)) {
      throw Object.assign(new Error('Rubric có tiêu chí không hợp lệ.'), { statusCode: 422 });
    }
    if (Math.abs(total - maxScore) > 0.01) {
      throw Object.assign(new Error(`Tổng điểm rubric (${total}) không bằng điểm tối đa (${maxScore}).`), { statusCode: 422 });
    }
    return {
      rubric: { rubric_id: String(rubric.rubric_id || rubricId || `${moduleName.toLowerCase()}-custom`), module: moduleName, max_score: maxScore, version: String(rubric.version || '1'), criteria },
      generated_rubric: null,
    };
  }

  const lines = String(aiCriteria || '').split(/\r?\n/).map(line => line.replace(/^[-*•\d.)\s]+/, '').trim()).filter(Boolean);
  const descriptions = lines.length ? lines : [`Hoàn thành đúng yêu cầu phần ${moduleName}`];
  const base = Math.floor((maxScore / descriptions.length) * 100) / 100;
  let allocated = 0;
  const criteria = descriptions.map((description, index) => {
    const score = index === descriptions.length - 1 ? roundScore(maxScore - allocated) : base;
    allocated += score;
    return {
      id: `legacy_c${index + 1}`,
      description,
      max_score: score,
      verification_type: qualitativePattern.test(description) ? 'ai_review' : 'ai_review',
      verification: {},
      grading_policy: colorPattern.test(description) ? 'ignore_color_differences' : 'standard',
      confidence: 0.5,
    };
  });
  const generated = { rubric_id: `${moduleName.toLowerCase()}-legacy-generated`, module: moduleName, max_score: maxScore, version: 'legacy-1', criteria };
  return {
    rubric: generated,
    generated_rubric: { status: 'needs_review', ...generated, message: 'Rubric tạm được tách từ tiêu chí văn bản; giáo viên cần xác nhận trước khi dùng rule kỹ thuật.' },
  };
}

export function criteriaForAi(rubric) {
  return rubric.criteria.filter(criterion => criterion.verification_type !== 'rule');
}

export function validateAiCriteriaResults(raw, criteria) {
  const allowed = new Map(criteria.map(criterion => [criterion.id, criterion]));
  const received = new Map();
  for (const item of Array.isArray(raw?.criteria_results) ? raw.criteria_results : []) {
    const criterion = allowed.get(String(item?.criterion_id || ''));
    if (!criterion || received.has(criterion.id)) continue;
    const scoreValue = Number(item.score);
    const score = Number.isFinite(scoreValue) ? Math.max(0, Math.min(scoreValue, criterion.max_score)) : 0;
    const status = ['passed', 'partial', 'failed', 'insufficient_evidence'].includes(item.status) ? item.status : 'insufficient_evidence';
    received.set(criterion.id, {
      criterion_id: criterion.id,
      description: criterion.description,
      verification_type: criterion.verification_type,
      status,
      score: roundScore(score),
      max_score: criterion.max_score,
      evidence: Array.isArray(item.evidence) ? item.evidence.map(String).slice(0, 20) : [],
      message: String(item.comment || ''),
      requires_teacher_review: Boolean(item.requires_teacher_review) || status === 'insufficient_evidence',
      confidence: status === 'insufficient_evidence'
        ? 0.25
        : (Array.isArray(item.evidence) && item.evidence.length ? 0.72 : 0.45),
    });
  }
  return criteria.map(criterion => received.get(criterion.id) || ({
    criterion_id: criterion.id,
    description: criterion.description,
    verification_type: criterion.verification_type,
    status: 'insufficient_evidence',
    score: 0,
    max_score: criterion.max_score,
    evidence: [],
    message: 'Gemini không trả kết quả hợp lệ cho tiêu chí này.',
    requires_teacher_review: true,
    confidence: 0.15,
  }));
}

export function reconcileAiVerification(firstResults, secondResults) {
  const secondById = new Map(secondResults.map(item => [item.criterion_id, item]));
  return firstResults.map(first => {
    const second = secondById.get(first.criterion_id);
    if (!second) return first;
    if (first.status === second.status) {
      const selected = Number(second.score) >= Number(first.score) ? second : first;
      return {
        ...selected,
        evidence: [...new Set([...(first.evidence || []), ...(second.evidence || [])])],
        message: [first.message, second.message].filter(Boolean).join(' | Kiểm định: '),
        confidence: ['passed', 'failed'].includes(first.status) ? 0.9 : 0.8,
        verification_outcome: 'confirmed',
        requires_teacher_review: Boolean(first.requires_teacher_review && second.requires_teacher_review),
      };
    }

    // Khi hai lượt mâu thuẫn, dùng mức điểm cao hơn để tránh trừ oan nhưng bắt buộc giáo viên xem lại.
    const selected = Number(second.score) > Number(first.score) ? second : first;
    return {
      ...selected,
      status: selected.status === 'failed' ? 'insufficient_evidence' : selected.status,
      evidence: [...new Set([...(first.evidence || []), ...(second.evidence || [])])],
      message: `Hai lượt AI chưa thống nhất. Lượt 1: ${first.message || first.status}. Lượt 2: ${second.message || second.status}.`,
      confidence: 0.55,
      verification_outcome: 'disputed',
      requires_teacher_review: true,
    };
  });
}
