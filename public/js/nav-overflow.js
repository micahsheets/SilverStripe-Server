(function () {
    const DESKTOP_MIN_WIDTH = 1024;

    function createOverflowController(options) {
        const {
            container,
            itemSelector,
            pinnedPredicate,
            observeRoot,
            getMaxButtons,
            getAvailableWidth
        } = options;

        if (!container) return null;

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

        let isInternalMutating = false;
        let lastSignature = '';

        function positionDropdown() {
            const rect = moreBtn.getBoundingClientRect();
            const dropWidth = Math.max(220, rect.width + 44);
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

        function toggleDropdown(e) {
            e.preventDefault();
            e.stopPropagation();
            const willOpen = !moreWrap.classList.contains('open');
            if (willOpen) {
                document.querySelectorAll('.nav-more-wrap.open').forEach(w => {
                    if (w !== moreWrap) w.classList.remove('open');
                });
                document.querySelectorAll('.nav-more-dropdown.open').forEach(d => {
                    if (d !== dropdown) d.classList.remove('open');
                });
                positionDropdown();
                moreWrap.classList.add('open');
                dropdown.classList.add('open');
                moreBtn.setAttribute('aria-expanded', 'true');
            } else {
                closeDropdown();
            }
        }

        moreBtn.addEventListener('click', toggleDropdown);

        document.addEventListener('click', (e) => {
            if (!moreWrap.contains(e.target) && !dropdown.contains(e.target)) {
                closeDropdown();
            }
        });

        dropdown.addEventListener('click', (e) => {
            const btn = e.target.closest(itemSelector);
            if (btn) {
                closeDropdown();
                if (btn.tagName === 'BUTTON') {
                    lastSignature = '';
                    requestAnimationFrame(() => layout());
                }
            }
        });

        function collectAllItems() {
            const inContainer = Array.from(container.querySelectorAll(':scope > ' + itemSelector));
            const inDropdown = Array.from(dropdown.querySelectorAll(':scope > ' + itemSelector));
            const merged = [];
            const seen = new Set();
            [...inContainer, ...inDropdown].forEach(el => {
                if (!seen.has(el)) {
                    seen.add(el);
                    merged.push(el);
                }
            });
            return merged;
        }

        function layout(force) {
            if (isInternalMutating) return;
            const allItems = collectAllItems();
            const sig = [
                window.innerWidth,
                container.clientWidth,
                allItems.map(el => (el.textContent || '').trim() + ':' + (el.classList.contains('active') ? '1' : '0')).join('|')
            ].join('::');

            if (!force && sig === lastSignature) {
                return;
            }
            lastSignature = sig;

            isInternalMutating = true;
            try {
                closeDropdown();
                moreWrap.style.display = 'none';

                if (allItems.length === 0) {
                    dropdown.innerHTML = '';
                    return;
                }

                const pinnedItems = pinnedPredicate ? allItems.filter(pinnedPredicate) : [];
                const flowItems = pinnedPredicate ? allItems.filter(el => !pinnedPredicate(el)) : allItems;

                dropdown.innerHTML = '';
                container.innerHTML = '';

                flowItems.forEach(btn => {
                    btn.classList.remove('nav-dropdown-item');
                    container.appendChild(btn);
                });
                pinnedItems.forEach(btn => {
                    btn.classList.remove('nav-dropdown-item');
                    container.appendChild(btn);
                });

                const maxAllowed = typeof getMaxButtons === 'function' ? getMaxButtons() : 99;

                const needsCollapse = () => {
                    const visibleCount = container.querySelectorAll(':scope > ' + itemSelector + ', :scope > .nav-more-wrap').length;
                    if (visibleCount > maxAllowed) return true;
                    if (container.scrollWidth > container.clientWidth + 2) return true;

                    if (typeof getAvailableWidth === 'function') {
                        const avail = getAvailableWidth();
                        if (avail > 80 && container.scrollWidth > avail) {
                            return true;
                        }
                    }

                    const children = Array.from(container.children).filter(el => el.style.display !== 'none');
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

                if (!needsCollapse() || flowItems.length <= 1) {
                    return;
                }

                moreWrap.style.display = 'inline-flex';
                if (pinnedItems.length > 0) {
                    container.insertBefore(moreWrap, pinnedItems[0]);
                } else {
                    container.appendChild(moreWrap);
                }

                const activeBtn = flowItems.find(b => b.classList.contains('active'));
                const candidates = [...flowItems].reverse().filter(b => b !== activeBtn);
                if (activeBtn) {
                    candidates.push(activeBtn);
                }

                const overflowed = [];
                for (const btn of candidates) {
                    if (!needsCollapse()) break;
                    if (btn.parentElement === container) {
                        container.removeChild(btn);
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
            } finally {
                isInternalMutating = false;
            }
        }

        if (typeof MutationObserver !== 'undefined') {
            const mo = new MutationObserver(() => {
                if (!isInternalMutating) {
                    requestAnimationFrame(() => layout(false));
                }
            });
            mo.observe(container, { childList: true, attributes: true, subtree: true, attributeFilter: ['class'] });
        }

        if (typeof ResizeObserver !== 'undefined') {
            const ro = new ResizeObserver(() => layout(false));
            ro.observe(observeRoot || container);
        }

        return { layout, closeDropdown, dropdown, moreWrap };
    }

    function initNavigationAndFilterBars() {
        const header = document.querySelector('.panel-header');
        const nav = header ? header.querySelector('.nav-tabs') : null;
        const brandWrap = header ? header.querySelector('.brand-wrap') : null;

        const topNavCtrl = nav ? createOverflowController({
            container: nav,
            itemSelector: 'a.nav-pill',
            pinnedPredicate: (el) => el.classList.contains('lock-btn'),
            observeRoot: header,
            getMaxButtons: () => (window.innerWidth <= 1300 ? 6 : 99),
            getAvailableWidth: () => {
                if (!header) return 0;
                const brandW = brandWrap ? brandWrap.getBoundingClientRect().width : 0;
                return Math.max(0, header.clientWidth - brandW - 48);
            }
        }) : null;

        const main = document.querySelector('.panel-main');
        const deviceTabs = main ? main.querySelector('.device-tabs') : null;
        const roomTabs = main ? (main.querySelector('.room-tabs.unified-room-tabs') || main.querySelector('.room-tabs')) : null;

        const deviceOrigParent = deviceTabs ? deviceTabs.parentElement : null;
        const deviceOrigNext = deviceTabs ? deviceTabs.nextSibling : null;
        const roomOrigParent = roomTabs ? roomTabs.parentElement : null;
        const roomOrigNext = roomTabs ? roomTabs.nextSibling : null;

        const relocatedActions = [];
        const candidateParents = new Set([roomOrigParent, deviceOrigParent].filter(p => p && p !== main));
        candidateParents.forEach(parent => {
            Array.from(parent.children).forEach(child => {
                if (child === deviceTabs || child === roomTabs) return;
                // Keep RodeCaster's Studio/Volume/Cut control strip in its own row
                if (child.querySelector('.rc-mode-controls, .rc-volume-control, .rc-transition-controls')) return;
                relocatedActions.push({
                    el: child,
                    origParent: parent,
                    origNext: child.nextSibling
                });
            });
        });

        let combinedBar = null;
        let combinedLeft = null;
        let combinedRight = null;
        let deviceCtrl = null;
        let roomCtrl = null;

        if (main && (deviceTabs || roomTabs)) {
            combinedBar = document.createElement('div');
            combinedBar.className = 'combined-filter-bar';
            combinedBar.style.display = 'none';

            combinedLeft = document.createElement('div');
            combinedLeft.className = 'combined-filter-left';

            combinedRight = document.createElement('div');
            combinedRight.className = 'combined-filter-right';

            combinedBar.appendChild(combinedLeft);
            combinedBar.appendChild(combinedRight);

            if (deviceTabs) {
                deviceCtrl = createOverflowController({
                    container: deviceTabs,
                    itemSelector: '.device-tab',
                    observeRoot: combinedBar,
                    getMaxButtons: () => 99
                });
            }

            if (roomTabs) {
                roomCtrl = createOverflowController({
                    container: roomTabs,
                    itemSelector: '.room-tab',
                    observeRoot: combinedBar,
                    getMaxButtons: () => 99
                });
            }
        }

        function syncCombinedFilterLayout() {
            const isDesktop = window.innerWidth >= DESKTOP_MIN_WIDTH;

            if (combinedBar && main) {
                if (isDesktop) {
                    if (!combinedBar.parentElement) {
                        main.insertBefore(combinedBar, main.firstChild);
                    }
                    combinedBar.style.display = 'flex';

                    if (deviceTabs && deviceTabs.parentElement !== combinedLeft) {
                        combinedLeft.appendChild(deviceTabs);
                    }
                    if (roomTabs && roomTabs.parentElement !== combinedRight) {
                        combinedRight.appendChild(roomTabs);
                    }

                    relocatedActions.forEach(item => {
                        if (item.el.parentElement !== combinedRight) {
                            combinedRight.appendChild(item.el);
                        }
                    });

                    candidateParents.forEach(origParent => {
                        const remaining = Array.from(origParent.children).filter(el => el.style.display !== 'none');
                        if (remaining.length === 0) {
                            origParent.dataset.combinedHidden = '1';
                            origParent.style.display = 'none';
                        }
                    });
                } else {
                    if (deviceTabs && deviceOrigParent && deviceTabs.parentElement !== deviceOrigParent) {
                        if (deviceOrigNext && deviceOrigNext.parentElement === deviceOrigParent) {
                            deviceOrigParent.insertBefore(deviceTabs, deviceOrigNext);
                        } else {
                            deviceOrigParent.appendChild(deviceTabs);
                        }
                    }
                    if (roomTabs && roomOrigParent && roomTabs.parentElement !== roomOrigParent) {
                        if (roomOrigNext && roomOrigNext.parentElement === roomOrigParent) {
                            roomOrigParent.insertBefore(roomTabs, roomOrigNext);
                        } else {
                            roomOrigParent.appendChild(roomTabs);
                        }
                    }
                    relocatedActions.forEach(item => {
                        if (item.el.parentElement !== item.origParent) {
                            if (item.origNext && item.origNext.parentElement === item.origParent) {
                                item.origParent.insertBefore(item.el, item.origNext);
                            } else {
                                item.origParent.appendChild(item.el);
                            }
                        }
                    });
                    candidateParents.forEach(origParent => {
                        if (origParent.dataset.combinedHidden === '1') {
                            origParent.style.display = '';
                            delete origParent.dataset.combinedHidden;
                        }
                    });
                    combinedBar.style.display = 'none';
                }
            }

            if (topNavCtrl) topNavCtrl.layout(false);
            if (isDesktop) {
                if (deviceCtrl) deviceCtrl.layout(false);
                if (roomCtrl) roomCtrl.layout(false);
            } else {
                if (deviceCtrl) {
                    deviceCtrl.closeDropdown();
                    Array.from(deviceCtrl.dropdown.children).forEach(el => {
                        el.classList.remove('nav-dropdown-item');
                        deviceTabs.appendChild(el);
                    });
                    deviceCtrl.moreWrap.style.display = 'none';
                }
                if (roomCtrl) {
                    roomCtrl.closeDropdown();
                    Array.from(roomCtrl.dropdown.children).forEach(el => {
                        el.classList.remove('nav-dropdown-item');
                        roomTabs.appendChild(el);
                    });
                    roomCtrl.moreWrap.style.display = 'none';
                }
            }
        }

        syncCombinedFilterLayout();
        window.addEventListener('resize', syncCombinedFilterLayout);
        setTimeout(syncCombinedFilterLayout, 250);
        setTimeout(syncCombinedFilterLayout, 900);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initNavigationAndFilterBars);
    } else {
        initNavigationAndFilterBars();
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

(function initConnectionDotMonitor() {
    const POLL_INTERVAL_MS = 10000;

    async function checkNetworkDot() {
        const dots = document.querySelectorAll('#connectionDot, .lan-status-dot');
        if (!dots.length) return;

        try {
            const controller = new AbortController();
            const timer = setTimeout(() => controller.abort(), 3000);
            const res = await fetch('/api/network-status', {
                credentials: 'same-origin',
                cache: 'no-store',
                signal: controller.signal
            });
            clearTimeout(timer);

            if (!res.ok) throw new Error('HTTP ' + res.status);
            const data = await res.json();
            const isOnline = Boolean(data && data.online);
            const totalDevices = Number(data && data.avDevices ? data.avDevices.total : 0);
            const hasNoDevices = isOnline && totalDevices === 0;

            dots.forEach((dot) => {
                dot.classList.toggle('offline', !isOnline);
                dot.classList.toggle('no-devices', hasNoDevices);
                if (hasNoDevices) {
                    dot.title = 'No AV devices configured in Admin';
                } else {
                    dot.title = data.reason || (isOnline ? 'LAN Connected' : 'LAN Offline');
                }
            });
        } catch (err) {
            dots.forEach((dot) => {
                dot.classList.add('offline');
                dot.title = 'Network check failed: ' + (err && err.message ? err.message : 'Offline');
            });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', checkNetworkDot);
    } else {
        checkNetworkDot();
    }

    window.addEventListener('online', checkNetworkDot);
    window.addEventListener('offline', checkNetworkDot);
    setInterval(checkNetworkDot, POLL_INTERVAL_MS);
})();

/* === NON-AUCTIONEER INACTIVITY AUTO-LOGOUT === */
(function initInactivityAutoLogout() {
    // Auctioneers remain logged in until manually logged out
    if (document.getElementById('aucTopbar') || window.location.pathname.indexOf('/panel/auctioneer') === 0) {
        return;
    }

    let timeoutMs = 0;
    let idleTimer = null;

    function triggerLogout() {
        window.location.href = '/panel/lock';
    }

    function resetIdleTimer() {
        if (timeoutMs <= 0) return;
        if (idleTimer) clearTimeout(idleTimer);
        idleTimer = setTimeout(triggerLogout, timeoutMs);
    }

    async function loadTimeoutConfig() {
        try {
            const res = await fetch('/api/system-power/status', {
                credentials: 'same-origin',
                cache: 'no-store'
            });
            if (!res.ok) return;
            const data = await res.json();
            const mins = Number(data && data.timeoutMinutes ? data.timeoutMinutes : 0);
            if (mins > 0) {
                timeoutMs = mins * 60 * 1000;
                ['pointerdown', 'touchstart', 'mousedown', 'keydown', 'scroll'].forEach(function (evt) {
                    window.addEventListener(evt, resetIdleTimer, { passive: true, capture: true });
                });
                resetIdleTimer();
            }
        } catch (e) {}
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', loadTimeoutConfig);
    } else {
        loadTimeoutConfig();
    }
})();
