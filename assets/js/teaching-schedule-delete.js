(() => {
  const dialog = document.getElementById('schedule-delete-dialog');
  const classForm = document.getElementById('delete-class-form');
  const title = document.getElementById('schedule-delete-title');
  const description = document.getElementById('schedule-delete-description');
  const warning = document.getElementById('schedule-delete-warning');
  const confirmButton = document.getElementById('confirm-schedule-delete');
  let resolveConfirmation = null;
  let deletingClass = false;

  document.getElementById('cancel-schedule-delete').addEventListener('click', () => dialog.close('cancel'));
  confirmButton.addEventListener('click', () => dialog.close('delete'));
  dialog.addEventListener('cancel', () => { dialog.returnValue = 'cancel'; });
  dialog.addEventListener('close', () => {
    const resolve = resolveConfirmation;
    resolveConfirmation = null;
    resolve?.(dialog.returnValue === 'delete');
  });

  const confirmDeletion = ({kind, name = ''}) => {
    if (resolveConfirmation) return Promise.resolve(false);
    const isClass = kind === 'class';
    title.textContent = isClass ? 'Xóa nhóm lịch?' : 'Xóa buổi dạy?';
    description.textContent = isClass
      ? 'Bạn muốn xóa nhóm lịch “' + (name || 'đã chọn') + '” và toàn bộ buổi dạy, điểm danh của nhóm?'
      : 'Bạn muốn xóa buổi dạy ' + (name ? '“' + name + '”' : 'đã chọn') + '?';
    warning.textContent = isClass
      ? 'Thao tác này không thể hoàn tác. Các khóa học và lớp học trong hệ thống được giữ nguyên.'
      : 'Nếu là lịch tự tạo, hệ thống sẽ xếp buổi thay thế sau ngày vừa xóa và các buổi chung còn lại.';
    confirmButton.textContent = isClass ? 'Xóa nhóm lịch' : 'Xóa buổi dạy';
    dialog.returnValue = '';
    return new Promise((resolve, reject) => {
      resolveConfirmation = resolve;
      try { dialog.showModal(); }
      catch (error) { resolveConfirmation = null; reject(error); }
    });
  };

  const deleteClass = async (classId, name) => {
    if (deletingClass || !Number.isInteger(Number(classId)) || Number(classId) <= 0) return;
    deletingClass = true;
    try {
      if (!await confirmDeletion({kind: 'class', name})) {
        deletingClass = false;
        return;
      }
      classForm.elements.namedItem('class_id').value = String(classId);
      HTMLFormElement.prototype.submit.call(classForm);
    } catch (error) {
      deletingClass = false;
      throw error;
    }
  };

  // Cover direct delete forms as well as the action inside the edit dialog.
  document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || form === classForm || form.elements.namedItem('action')?.value !== 'delete_class') return;
    event.preventDefault();
    deleteClass(form.elements.namedItem('class_id')?.value, form.closest('.info-cell')?.querySelector('.class-title')?.textContent);
  });
  let feedbackTimer;
  const showFeedback = (message) => {
    const feedback = document.getElementById('schedule-delete-feedback');
    if (!feedback) return;
    window.clearTimeout(feedbackTimer);
    feedback.textContent = message || 'Đã xóa buổi dạy.';
    feedback.hidden = false;
    feedbackTimer = window.setTimeout(() => { feedback.hidden = true; }, 4000);
  };
  window.lmsScheduleDelete = {confirm: confirmDeletion, deleteClass, showFeedback};
})();
