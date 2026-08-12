(function () {
    'use strict';

    const prepare = (canvas, height) => {
        const ratio = window.devicePixelRatio || 1;
        const width = Math.max(280, canvas.parentElement?.clientWidth || canvas.clientWidth || 600);
        canvas.style.width = '100%';
        canvas.style.height = height + 'px';
        canvas.width = Math.round(width * ratio);
        canvas.height = Math.round(height * ratio);
        const context = canvas.getContext('2d');
        context.setTransform(ratio, 0, 0, ratio, 0, 0);
        context.clearRect(0, 0, width, height);
        context.font = '13px system-ui, sans-serif';
        return { context, width, height };
    };

    const watch = (canvas, draw) => {
        draw();
        if ('ResizeObserver' in window) new ResizeObserver(draw).observe(canvas.parentElement || canvas);
        else window.addEventListener('resize', draw, { passive: true });
    };

    const shorten = (context, value, maxWidth) => {
        let text = String(value);
        while (text.length > 3 && context.measureText(text + '…').width > maxWidth) text = text.slice(0, -1);
        return text === String(value) ? text : text + '…';
    };

    const bar = (canvas, labels, values, options = {}) => watch(canvas, () => {
        const { context: ctx, width, height } = prepare(canvas, options.height || 320);
        const pad = { left: 42, right: 14, top: 16, bottom: 54 };
        const chartWidth = width - pad.left - pad.right;
        const chartHeight = height - pad.top - pad.bottom;
        const max = Math.max(1, ...values.map(Number));
        const steps = Math.min(5, max);
        ctx.strokeStyle = options.gridColor || 'rgba(255,255,255,.1)';
        ctx.fillStyle = options.textColor || '#94a3b8';
        ctx.textAlign = 'right';
        for (let i = 0; i <= steps; i++) {
            const y = pad.top + chartHeight - chartHeight * i / steps;
            ctx.beginPath(); ctx.moveTo(pad.left, y); ctx.lineTo(width - pad.right, y); ctx.stroke();
            ctx.fillText(String(Math.round(max * i / steps)), pad.left - 8, y + 4);
        }
        const slot = chartWidth / Math.max(1, values.length);
        const barWidth = Math.max(5, Math.min(54, slot * .62));
        values.forEach((value, index) => {
            const numeric = Number(value) || 0;
            const x = pad.left + slot * index + (slot - barWidth) / 2;
            const barHeight = chartHeight * numeric / max;
            ctx.fillStyle = options.color || '#6366f1';
            ctx.fillRect(x, pad.top + chartHeight - barHeight, barWidth, barHeight);
            ctx.fillStyle = options.textColor || '#94a3b8';
            ctx.textAlign = 'center';
            if (slot >= 35 || index % Math.ceil(45 / slot) === 0) {
                ctx.fillText(shorten(ctx, labels[index] ?? '', Math.max(45, slot * 2)), x + barWidth / 2, height - 24);
            }
        });
    });

    const doughnut = (canvas, labels, values, options = {}) => watch(canvas, () => {
        const { context: ctx, width, height } = prepare(canvas, options.height || 300);
        const colors = options.colors || ['#6366f1', '#f43f5e'];
        const total = values.reduce((sum, value) => sum + (Number(value) || 0), 0);
        const radius = Math.min(width, height - 52) * .34;
        const centerX = width / 2, centerY = (height - 42) / 2;
        let start = -Math.PI / 2;
        values.forEach((value, index) => {
            const angle = total ? (Number(value) || 0) / total * Math.PI * 2 : 0;
            ctx.beginPath(); ctx.arc(centerX, centerY, radius, start, start + angle); ctx.arc(centerX, centerY, radius * .58, start + angle, start, true); ctx.closePath();
            ctx.fillStyle = colors[index % colors.length]; ctx.fill(); start += angle;
        });
        const legendWidth = Math.min(150, width / Math.max(1, labels.length));
        const legendStart = (width - legendWidth * labels.length) / 2;
        labels.forEach((label, index) => {
            const x = legendStart + index * legendWidth;
            ctx.fillStyle = colors[index % colors.length]; ctx.fillRect(x, height - 24, 12, 12);
            ctx.fillStyle = options.textColor || '#f8fafc'; ctx.textAlign = 'left';
            ctx.fillText(`${label} (${Number(values[index]) || 0})`, x + 18, height - 13);
        });
    });

    const radar = (canvas, labels, values, options = {}) => watch(canvas, () => {
        const { context: ctx, width, height } = prepare(canvas, options.height || 360);
        const count = labels.length;
        if (!count) return;
        const max = options.max || 10, radius = Math.min(width, height) * .34, cx = width / 2, cy = height / 2;
        const point = (index, scale) => ({ x: cx + Math.cos(-Math.PI / 2 + index * Math.PI * 2 / count) * radius * scale, y: cy + Math.sin(-Math.PI / 2 + index * Math.PI * 2 / count) * radius * scale });
        ctx.strokeStyle = options.gridColor || 'rgba(255,255,255,.12)';
        for (let ring = 1; ring <= 5; ring++) {
            ctx.beginPath();
            for (let i = 0; i < count; i++) { const p = point(i, ring / 5); i ? ctx.lineTo(p.x, p.y) : ctx.moveTo(p.x, p.y); }
            ctx.closePath(); ctx.stroke();
        }
        for (let i = 0; i < count; i++) { const p = point(i, 1); ctx.beginPath(); ctx.moveTo(cx, cy); ctx.lineTo(p.x, p.y); ctx.stroke(); }
        ctx.beginPath();
        values.forEach((value, index) => { const p = point(index, Math.max(0, Math.min(max, Number(value) || 0)) / max); index ? ctx.lineTo(p.x, p.y) : ctx.moveTo(p.x, p.y); });
        ctx.closePath(); ctx.fillStyle = options.fill || 'rgba(99,102,241,.2)'; ctx.fill(); ctx.strokeStyle = options.color || '#6366f1'; ctx.lineWidth = 2; ctx.stroke();
        labels.forEach((label, index) => { const p = point(index, 1.15); ctx.fillStyle = options.textColor || '#f8fafc'; ctx.textAlign = p.x < cx - 5 ? 'right' : p.x > cx + 5 ? 'left' : 'center'; ctx.fillText(shorten(ctx, label, 120), p.x, p.y + 4); });
    });

    window.NativeCharts = { bar, doughnut, radar };
})();
