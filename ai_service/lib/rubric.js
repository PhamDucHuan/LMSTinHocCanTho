const colorPattern = /\b(màu|mau|color|colour|font color|fill|background|nền|to màu|tô màu)\b/i;

function roundScore(value) {
  return Math.round(Number(value) * 100) / 100;
}

function inferLegacyTechnicalVerification(description, moduleName) {
  const module = String(moduleName || '').toLowerCase();
  const plain = String(description || '').normalize('NFD').replace(/\p{M}/gu, '').toLowerCase().replaceAll('đ', 'd');
  if (module === 'excel') {
    const cellRange = plain.match(/\b([a-z]{1,3}\d+:[a-z]{1,3}\d+)\b/)?.[1]?.toUpperCase();
    if (/dat ten vung|named\s*range|defined\s*name|define\s*name/.test(plain)) {
      const quotedName = plain.match(/["']([a-z_][a-z0-9_.]*)["']/)?.[1];
      const assignedName = plain.match(/(?:dat ten vung|named\s*range).*?\bla\s+([a-z_][a-z0-9_.]*)/)?.[1]
        || plain.match(/named\s*range\s*[:=]\s*([a-z_][a-z0-9_.]*)/)?.[1];
      return {
        type: 'excel_named_range_exists', rule_weight: 0.7, expected_kind: 'range',
        ...((quotedName || assignedName) ? { expected_name: quotedName || assignedName } : {}),
        ...(cellRange ? { expected_reference: cellRange } : {}),
      };
    }
    if (/data\s*validation|xac thuc du lieu|kiem tra du lieu|danh sach tha xuong|drop\s*down\s*list/.test(plain)) {
      const expectedType = /danh sach|drop\s*down|\blist\b/.test(plain) ? 'list'
        : /so nguyen|whole\s*number/.test(plain) ? 'whole'
          : /thap phan|decimal/.test(plain) ? 'decimal'
            : /ngay|\bdate\b/.test(plain) ? 'date'
              : /tuy chinh|custom/.test(plain) ? 'custom' : null;
      return { type: 'excel_data_validation', rule_weight: 0.7, ...(expectedType ? { expected_type: expectedType } : {}), ...(cellRange ? { range: cellRange } : {}) };
    }
    if (/what\s*[- ]?if.*data\s*table|data\s*table|bang du lieu mot bien|bang du lieu hai bien/.test(plain)) {
      const twoVariable = /hai bien|2 bien|two.variable/.test(plain);
      return { type: 'excel_what_if_data_table_exists', rule_weight: 0.7, ...(twoVariable ? { two_variable: true } : {}), ...(cellRange ? { range: cellRange } : {}) };
    }
    if (/format\s*as\s*table|excel\s*table|listobject|tao bang co cau truc/.test(plain)) return { type: 'excel_structured_table_exists', rule_weight: 0.7, ...(cellRange ? { range: cellRange } : {}) };
    return null;
  }
  if (module === 'powerpoint') {
    const slideNumbers = [...plain.matchAll(/slide\s*(\d+)/g)].map(match => Number(match[1]));
    if (/custom\s*slide\s*show|trinh chieu tuy bien|trinh chieu tuy chon/.test(plain)) {
      const quotedName = plain.match(/["']([^"']+)["']/)?.[1];
      const listedSlides = /(?:gom|cac)\s+slide/.test(plain) ? slideNumbers : [];
      return { type: 'ppt_custom_slide_show_exists', rule_weight: 0.7, ...(quotedName ? { expected_name: quotedName } : {}), ...(listedSlides.length ? { expected_slides: listedSlides } : {}) };
    }
    if (/slide\s*master|trang chieu cai|ban cai(?: trang chieu)?/.test(plain)) {
      const imageRequired = /logo|hinh anh|picture|image/.test(plain);
      const quotedText = plain.match(/["']([^"']+)["']/)?.[1];
      if (imageRequired || quotedText) return { type: 'ppt_master_object_exists', rule_weight: 0.7, ...(imageRequired ? { object_type: 'image' } : {}), ...(quotedText ? { text_contains: quotedText } : {}) };
      if (/so trang|slide number/.test(plain)) return { type: 'ppt_slide_master_exists', rule_weight: 0.7, slide_number: true };
      if (/chan trang|footer/.test(plain)) return { type: 'ppt_slide_master_exists', rule_weight: 0.7, footer: true };
      if (/hinh nen|background|\bnen\b/.test(plain)) return { type: 'ppt_slide_master_exists', rule_weight: 0.7, background: true };
      return null;
    }
    if (/lien ket|hyperlink|action\s*button|nut hanh dong/.test(plain) && /slide|trang chieu/.test(plain)) {
      const sourceAndTarget = plain.match(/(?:tu|from)\s*slide\s*(\d+).*?(?:den|toi|to)\s*slide\s*(\d+)/);
      const targetOnly = plain.match(/(?:den|toi|to)\s*slide\s*(\d+)/);
      const sourceSlide = sourceAndTarget ? Number(sourceAndTarget[1]) : null;
      const targetSlide = sourceAndTarget ? Number(sourceAndTarget[2]) : (targetOnly ? Number(targetOnly[1]) : (slideNumbers.length === 1 ? slideNumbers[0] : null));
      const navigation = /ke tiep|next/.test(plain) ? 'nextslide'
        : /truoc|previous/.test(plain) ? 'previousslide'
          : /dau tien|first/.test(plain) ? 'firstslide'
            : /cuoi|last/.test(plain) ? 'lastslide' : null;
      return { type: 'ppt_internal_hyperlink_exists', rule_weight: 0.7, ...(sourceSlide ? { source_slide: sourceSlide } : {}), ...(targetSlide ? { target_slide: targetSlide } : {}), ...(navigation ? { navigation } : {}) };
    }
    return null;
  }
  if (module !== 'word') return null;
  if (/muc luc|table of contents|\btoc\b/.test(plain)) {
    const range = plain.match(/(?:cap|level)\s*(\d+)\s*(?:-|den|toi)\s*(\d+)/);
    const count = plain.match(/(\d+)\s*(?:cap|level)/);
    return {
      type: 'word_toc_exists',
      rule_weight: 0.7,
      ...(range ? { from_level: Number(range[1]), to_level: Number(range[2]) } : (count ? { from_level: 1, to_level: Number(count[1]) } : {})),
    };
  }
  if (/smart\s*art/.test(plain)) return { type: 'word_smartart_exists', rule_weight: 0.7 };
  if (/bieu mau|content\s*control|check\s*box|drop\s*down|combo\s*box|\bform\b/.test(plain)) {
    const controlType = /check\s*box/.test(plain) ? 'checkbox'
      : /drop\s*down/.test(plain) ? 'dropdown'
        : /combo\s*box/.test(plain) ? 'combobox'
          : /ngay|date/.test(plain) ? 'date' : null;
    return { type: 'word_form_control_exists', rule_weight: 0.7, ...(controlType ? { control_type: controlType } : {}) };
  }
  if (/cong thuc|formula|sum\s*\(\s*(?:above|below|left|right)/.test(plain) && /\bbang\b|table|above|below|left|right/.test(plain)) {
    const functionMatch = plain.match(/\b(sum|average|count|max|min|product|round)\b/);
    const expectedFunction = functionMatch?.[1]?.toUpperCase() || (/\btong\b/.test(plain) ? 'SUM' : null);
    return { type: 'word_table_formula_exists', rule_weight: 0.7, ...(expectedFunction ? { expected_function: expectedFunction } : {}) };
  }
  return null;
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
    const technicalVerification = inferLegacyTechnicalVerification(description, moduleName);
    return {
      id: `legacy_c${index + 1}`,
      description,
      max_score: score,
      verification_type: technicalVerification ? 'mixed' : 'ai_review',
      verification: technicalVerification || {},
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
