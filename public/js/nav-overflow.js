(function () {
    function initNavOverflow() {
        const header = document.querySelector('.panel-header');
        const nav = header ? header.querySelector('.nav-tabs') : null;
        if (!header || !nav) return;

        const allItems = Array.from(nav.querySelectorAll(':scope > a.nav-pill'));
        if (allItems.length === 0) return;

        const lockBtn = allItems.find(el => el.classList.contains('lock-btn')) || null;
        const moduleBtns = allItems.filter(el => !el.classList.contains('lock-btn'));

        const moreWrap = document.createElement('div');
        moreWrap.className = 'nav-more-wrap';
        moreWrap.style.display = 'none';

        const moreBtn = document.createElement('button');
        moreBtn.type = 'button';
        moreBtn.className = 'nav-pill nav-more-btn';
        moreBtn.setAttribute('aria-expanded', 'false');
        moreBtn.innerHTML = 'More <span class="nav-more-caret">▾</span>';

        const dropdown = document.createElement('div');
        dropdown.className = 'nav-more-dropdown';
        document.body.appendChild(dropdown);

        moreWrap.appendChild(moreBtn);

        function positionDropdown() {
            const rect = moreBtn.getBoundingClientRect();
            const dropWidth = Math.max(220, rect.width + 40);
            const left = Math.min(
                window.innerWidth - dropWidth - 12,
                Math.max(12, rect.right - dropWidth)
            );
            dropdown.style.top = (rect.bottom + 8) + 'px';
            dropdown.style.left = left + 'px';
            dropdown.style.width = dropWidth + 'px';
        }

        function closeDropdown() {
            moreWrap.classList.remove('open');
            dropdown.classList.remove('open');
            moreBtn.setAttribute('aria-expanded', 'false');
        }

        moreBtn.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            const willOpen = !moreWrap.classList.contains('open');
            if (willOpen) {
                positionDropdown();
                moreWrap.classList.add('open');
                dropdown.classList.add('open');
                moreBtn.setAttribute('aria-expanded', 'true');
            } else {
                closeDropdown();
            }
        });

        document.addEventListener('click', (e) => {
            if (!moreWrap.contains(e.target) && !dropdown.contains(e.target)) {
                closeDropdown();
            }
        });

        function layoutNav() {
            closeDropdown();
            moreWrap.style.display = 'none';
            dropdown.innerHTML = '';
            nav.innerHTML = '';

            moduleBtns.forEach(btn => {
                btn.classList.remove('nav-dropdown-item');
                nav.appendChild(btn);
            });
            if (lockBtn) {
                nav.appendChild(lockBtn);
            }

            const isCompactScreen = window.innerWidth <= 900;
            const maxAllowedButtons = isCompactScreen ? 6 : 99;

            const needsCollapse = () => {
                const currentCount = nav.querySelectorAll(':scope > .nav-pill, :scope > .nav-more-wrap').length;
                if (currentCount > maxAllowedButtons) return true;
                if (nav.scrollWidth > nav.clientWidth + 2) return true;

                const children = Array.from(nav.children).filter(el => el.style.display !== 'none');
                if (children.length > 1) {
                    const firstTop = children[0].offsetTop;
                    for (let i = 1; i < children.length; i++) {
                        if (Math.abs(children[i].offsetTop - firstTop) > 6) {
                            return true;
                        }
                    }
                }
                return false;
            };

            if (!needsCollapse() || moduleBtns.length <= 1) {
                return;
            }

            moreWrap.style.display = 'inline-flex';
            if (lockBtn) {
                nav.insertBefore(moreWrap, lockBtn);
            } else {
                nav.appendChild(moreWrap);
            }

            const activeBtn = moduleBtns.find(b => b.classList.contains('active'));
            const candidates = [...moduleBtns].reverse().filter(b => b !== activeBtn);
            if (activeBtn) {
                candidates.push(activeBtn);
            }

            const overflowed = [];
            for (const btn of candidates) {
                if (!needsCollapse()) break;
                if (btn.parentElement === nav) {
                    nav.removeChild(btn);
                    overflowed.unshift(btn);
                }
            }

            if (overflowed.length === 0) {
                moreWrap.style.display = 'none';
                return;
            }

            const hasActiveInDropdown = overflowed.some(b => b.classList.contains('active'));
            moreBtn.classList.toggle('active', hasActiveInDropdown);

            overflowed.forEach(btn => {
                btn.classList.add('nav-dropdown-item');
                dropdown.appendChild(btn);
            });
        }

        layoutNav();
        window.addEventListener('resize', layoutNav);
        if (typeof ResizeObserver !== 'undefined') {
            const ro = new ResizeObserver(() => layoutNav());
            ro.observe(header);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initNavOverflow);
    } else {
        initNavOverflow();
    }
})();

(function () {
    function syncSliderFill(el) {
        if (!el || el.type !== 'range') return;
        const min = parseFloat(el.min || '0');
        const max = parseFloat(el.max || '100');
        const val = parseFloat(el.value || '0');
        const pct = max > min ? ((val - min) / (max - min)) * 100 : 0;
        el.style.setProperty('--slider-pct', Math.max(0, Math.min(100, pct)) + '%');
    }

    function syncAllSliders(root) {
        (root || document).querySelectorAll('input[type="range"]').forEach(syncSliderFill);
    }

    document.addEventListener('input', (e) => syncSliderFill(e.target), true);
    document.addEventListener('change', (e) => syncSliderFill(e.target), true);

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => syncAllSliders(document));
    } else {
        syncAllSliders(document);
    }

    if (typeof MutationObserver !== 'undefined') {
        const mo = new MutationObserver((mutations) => {
            for (const m of mutations) {
                m.addedNodes.forEach((node) => {
                    if (node.nodeType === 1) {
                        if (node.matches && node.matches('input[type="range"]')) syncSliderFill(node);
                        syncAllSliders(node);
                    }
                });
            }
        });
        const startObserve = () => {
            if (document.body) mo.observe(document.body, { childList: true, subtree: true });
        };
        if (document.body) startObserve();
        else document.addEventListener('DOMContentLoaded', startObserve);
    }
})();

(function () {
    function initAudioScrollCue() {
        if (!document.body.classList.contains('audio-panel')) return;
        const main = document.querySelector('.panel-main');
        if (!main) return;

        let cue = main.querySelector('.scroll-more-cue');
        if (!cue) {
            cue = document.createElement('div');
            cue.className = 'scroll-more-cue';
            cue.innerHTML = '<button type="button" class="scroll-more-pill">↓ Scroll for more</button>';
            main.appendChild(cue);
        }

        function getActiveScroller() {
            const p300Grid = main.querySelector('.p300-channels-grid');
            if (p300Grid && p300Grid.offsetParent !== null) return p300Grid;

            const dtxControls = Array.from(main.querySelectorAll('.audio-controls')).find(el => el.offsetParent !== null);
            if (dtxControls) return dtxControls;

            return null;
        }

        function updateCue() {
            const scroller = getActiveScroller();
            if (!scroller) {
                cue.classList.remove('visible');
                return;
            }
            const hasMoreBelow = (scroller.scrollHeight - scroller.clientHeight - scroller.scrollTop) > 12;
            cue.classList.toggle('visible', hasMoreBelow);
        }

        cue.querySelector('.scroll-more-pill').addEventListener('click', () => {
            const scroller = getActiveScroller();
            if (scroller) {
                scroller.scrollBy({ top: 180, behavior: 'smooth' });
            }
        });

        // Listen to scroll events in capture phase so dynamically created P300 grids are caught automatically
        main.addEventListener('scroll', updateCue, true);
        window.addEventListener('resize', updateCue);

        if (typeof MutationObserver !== 'undefined') {
            const mo = new MutationObserver(() => {
                requestAnimationFrame(updateCue);
            });
            mo.observe(main, { childList: true, subtree: true, attributes: true, attributeFilter: ['style', 'class'] });
        }

        setTimeout(updateCue, 150);
        setTimeout(updateCue, 600);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAudioScrollCue);
    } else {
        initAudioScrollCue();
    }
})();
