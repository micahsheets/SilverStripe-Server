<style>
.vpx-preview-wrap {
    position: relative;
    width: 100%;
    aspect-ratio: 16 / 9;
    background: #0f172a;
    border-radius: 6px;
    overflow: hidden;
    margin-bottom: 8px;
    border: 1px solid rgba(148, 163, 184, 0.2);
}

.vpx-preview-wrap img.vpx-preview-img {
    position: relative;
    z-index: 1;
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
    opacity: 0;
    transition: opacity 0.25s ease;
}

.vpx-preview-wrap img.vpx-preview-img.is-loaded {
    opacity: 1;
}

.vpx-preview-placeholder {
    position: absolute;
    inset: 0;
    z-index: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #64748b;
    font-size: 11px;
    letter-spacing: 0.03em;
    text-transform: uppercase;
    pointer-events: none;
}

.cec-btn.disconnect {
    background: rgba(245, 158, 11, 0.16);
    color: #fbbf24;
    border: 1px solid rgba(245, 158, 11, 0.35);
}

.cec-btn.disconnect:hover:not(:disabled) {
    background: rgba(245, 158, 11, 0.28);
}

.cec-btn.disconnect:disabled {
    opacity: 0.4;
    cursor: default;
}

body[data-theme="light"] .cec-btn.disconnect {
    background: rgba(217, 119, 6, 0.12);
    color: #b45309;
    border-color: rgba(217, 119, 6, 0.35);
}
</style>
