const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

function fixture() {
  class Element extends EventTarget {
    textContent = '';
    value = '';
    click() { this.dispatchEvent(new Event('click')); }
  }
  class Form extends Element {
    constructor(values) {
      super();
      this.fields = Object.fromEntries(Object.entries(values).map(([name, value]) => [name, {value}]));
      this.elements = {namedItem: name => this.fields[name]};
      this.submissions = [];
    }
    submit() { this.submissions.push(Object.fromEntries(Object.entries(this.fields).map(([name, field]) => [name, field.value]))); }
    closest() { return null; }
  }
  class Dialog extends Element {
    open = false;
    returnValue = '';
    showModal() { assert.equal(this.open, false); this.open = true; }
    close(value) {
      if (value !== undefined) this.returnValue = value;
      this.open = false;
      this.dispatchEvent(new Event('close'));
    }
    escape() { this.dispatchEvent(new Event('cancel')); this.close(); }
  }
  const elements = Object.fromEntries(['schedule-delete-title', 'schedule-delete-description', 'schedule-delete-warning',
    'confirm-schedule-delete', 'cancel-schedule-delete', 'schedule-delete-feedback'].map(id => [id, new Element()]));
  elements['schedule-delete-dialog'] = new Dialog();
  const form = elements['delete-class-form'] = new Form({class_id: '', action: 'delete_class', csrf_token: 'valid-token', date: '2026-11-09', scope: 'mine'});
  // Even a control shadowing form.submit must not prevent native submission.
  form.submit = () => { throw new Error('Must submit the independent form through the native method.'); };
  const listeners = [];
  const document = {
    getElementById: id => elements[id],
    addEventListener: (type, listener) => { if (type === 'submit') listeners.push(listener); },
  };
  const timers = new Map();
  let nextTimer = 1;
  const window = {
    setTimeout: callback => { const id = nextTimer++; timers.set(id, callback); return id; },
    clearTimeout: id => { timers.delete(id); },
  };
  vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../assets/js/teaching-schedule-delete.js'), 'utf8'), {
    document, window, HTMLFormElement: Form,
  });
  return {api: window.lmsScheduleDelete, elements, form, dialog: elements['schedule-delete-dialog'], Form, listeners, timers};
}

test('Cancel and Escape never submit a class deletion', async () => {
  const {api, elements, dialog, form} = fixture();
  const cancelled = api.deleteClass(7, 'Lớp A');
  assert.equal(dialog.open, true);
  elements['cancel-schedule-delete'].click();
  await cancelled;
  assert.equal(form.submissions.length, 0);
  const escaped = api.deleteClass(8, 'Lớp B');
  dialog.escape();
  await escaped;
  assert.equal(form.submissions.length, 0);
});

test('Confirm posts the selected class, CSRF, week and personal scope in its own form', async () => {
  const {api, elements, form} = fixture();
  const pending = api.deleteClass(7, '<img src=x onerror=alert(1)>');
  assert.equal(elements['schedule-delete-title'].textContent, 'Xóa nhóm lịch?');
  assert.match(elements['schedule-delete-description'].textContent, /<img src=x onerror=alert\(1\)>/);
  assert.equal(elements['schedule-delete-description'].innerHTML, undefined);
  elements['confirm-schedule-delete'].click();
  await pending;
  assert.deepEqual(form.submissions, [{class_id: '7', action: 'delete_class', csrf_token: 'valid-token', date: '2026-11-09', scope: 'mine'}]);
});

test('Repeated clicks cannot change the class waiting for confirmation or submit twice', async () => {
  const {api, elements, form} = fixture();
  const pending = api.deleteClass(7, 'Lớp A');
  await api.deleteClass(8, 'Lớp B');
  assert.match(elements['schedule-delete-description'].textContent, /Lớp A/);
  elements['confirm-schedule-delete'].click();
  await pending;
  await api.deleteClass(8, 'Lớp B');
  assert.equal(form.submissions.length, 1);
  assert.equal(form.submissions[0].class_id, '7');
});

test('Slot confirmation resolves only on explicit delete and cannot inherit an old confirmation', async () => {
  const {api, elements, form} = fixture();
  const confirmed = api.confirm({kind: 'slot', name: 'Lớp A · 09/11/2026'});
  assert.equal(elements['confirm-schedule-delete'].textContent, 'Xóa buổi dạy');
  elements['confirm-schedule-delete'].click();
  assert.equal(await confirmed, true);
  const cancelled = api.confirm({kind: 'slot'});
  elements['cancel-schedule-delete'].click();
  assert.equal(await cancelled, false);
  assert.equal(form.submissions.length, 0);
});

test('Direct delete forms are intercepted and confirmed using the selected ID', async () => {
  const {elements, form, Form, listeners} = fixture();
  const directForm = new Form({action: 'delete_class', class_id: '12'});
  let prevented = false;
  listeners[0]({target: directForm, preventDefault: () => { prevented = true; }});
  assert.equal(prevented, true);
  assert.equal(form.submissions.length, 0);
  elements['confirm-schedule-delete'].click();
  await Promise.resolve();
  assert.equal(form.submissions[0].class_id, '12');
  let nativePrevented = false;
  listeners[0]({target: form, preventDefault: () => { nativePrevented = true; }});
  assert.equal(nativePrevented, false);
});

test('Deletion feedback is nonblocking and repeated messages reset dismissal', () => {
  const {api, elements, timers, form, dialog} = fixture();
  api.showFeedback('Đã xóa buổi dạy.');
  const feedback = elements['schedule-delete-feedback'];
  assert.equal(feedback.hidden, false);
  assert.equal(feedback.textContent, 'Đã xóa buổi dạy.');
  api.showFeedback('Đã xếp buổi thay thế.');
  assert.equal(timers.size, 1);
  assert.equal(feedback.textContent, 'Đã xếp buổi thay thế.');
  assert.equal(dialog.open, false);
  assert.equal(form.submissions.length, 0);
  [...timers.values()][0]();
  assert.equal(feedback.hidden, true);
});
