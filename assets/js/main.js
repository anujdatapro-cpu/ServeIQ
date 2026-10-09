/**
 * ServeIQ — Modern SaaS Client-side Interactions
 * Phase 1-10 Integrated Micro-interactions
 */

document.addEventListener('DOMContentLoaded', () => {
    // The theme is set in the document head to avoid a first-paint flash.
    const themeButtons = document.querySelectorAll('[data-theme-toggle]');
    const themeIcon = document.querySelector('[data-theme-icon]');
    const themeLabel = document.querySelector('[data-theme-label]');
    const syncThemeControls = () => {
        const dark = document.documentElement.dataset.theme === 'dark';
        themeButtons.forEach(button => {
            button.setAttribute('aria-pressed', String(dark));
            button.setAttribute('aria-label', dark ? 'Switch to light mode' : 'Switch to dark mode');
        });
        if (themeIcon) themeIcon.className = `bi ${dark ? 'bi-sun' : 'bi-moon-stars'}`;
        if (themeLabel) themeLabel.textContent = dark ? 'Switch to light mode' : 'Switch to dark mode';
    };
    syncThemeControls();
    themeButtons.forEach(button => button.addEventListener('click', () => {
        const nextTheme = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
        document.documentElement.dataset.theme = nextTheme;
        try { localStorage.setItem('serveiq-theme', nextTheme); } catch (_) {}
        syncThemeControls();
    }));

    // Keep the public navigation functional when the optional Bootstrap CDN is unavailable.
    const navigationToggle = document.querySelector('.navbar-toggler');
    const navigationMenu = document.querySelector('#mainNavigation');
    if (navigationToggle && navigationMenu && !window.bootstrap) {
        navigationToggle.addEventListener('click', () => {
            const isExpanded = navigationToggle.getAttribute('aria-expanded') === 'true';
            navigationToggle.setAttribute('aria-expanded', String(!isExpanded));
            navigationMenu.classList.toggle('show', !isExpanded);
        });
    }

    // Registration checks also run on the server in validation.php.
    const strongPassword = document.querySelector('[data-strong-password]');
    const passwordConfirmation = document.querySelector('[data-password-confirm]');
    if (strongPassword && passwordConfirmation) {
        const validatePasswordConfirmation = () => {
            passwordConfirmation.setCustomValidity(
                passwordConfirmation.value === strongPassword.value ? '' : 'Passwords do not match.'
            );
        };
        strongPassword.addEventListener('input', validatePasswordConfirmation);
        passwordConfirmation.addEventListener('input', validatePasswordConfirmation);
    }

    // -------------------------------------------------------------------------
    // 1. Problem Solver & Quick Chips (Customer Experience)
    // -------------------------------------------------------------------------
    const solveTextarea = document.querySelector('#solveTextarea, #problemDescription');
    const solveSubmitBtn = document.querySelector('#solveSubmitBtn, #problemSubmitLink');
    const solveCharCount = document.querySelector('#solveCharCount, #characterCount');
    const quickChips = document.querySelectorAll('.quick-chip');

    if (solveTextarea) {
        const updateSolveCount = () => {
            const length = solveTextarea.value.length;
            if (solveCharCount) {
                solveCharCount.textContent = `${length} / 5000`;
            }
            if (solveSubmitBtn) {
                solveSubmitBtn.classList.toggle('disabled', length < 20);
                solveSubmitBtn.setAttribute('aria-disabled', length < 20 ? 'true' : 'false');
            }
            const validation = document.querySelector('#problemValidation');
            if (validation && length > 0 && length < 20) {
                validation.textContent = 'Add a little more detail (at least 20 characters).';
            } else if (validation) {
                validation.textContent = '';
            }
        };

        solveTextarea.addEventListener('input', updateSolveCount);
        updateSolveCount();

        // Handle Quick Chips
        quickChips.forEach(chip => {
            chip.addEventListener('click', () => {
                const text = chip.getAttribute('data-problem') || chip.textContent.trim();
                solveTextarea.value = text;
                solveTextarea.focus();
                updateSolveCount();
            });
        });
    }

    const analyzerCard = document.querySelector('#problem-box[data-preview-url]');
    const analyzeButton = document.querySelector('#problemAnalyzeButton');
    const analysisResult = document.querySelector('#problemAnalysisResult');
    const problemValidation = document.querySelector('#problemValidation');
    if (analyzerCard && analyzeButton && solveTextarea && analysisResult) {
        analyzeButton.addEventListener('click', async () => {
            const description = solveTextarea.value.trim();
            if (description.length < 20 || description.length > 5000) {
                if (problemValidation) problemValidation.textContent = 'Describe the problem in 20 to 5,000 characters before previewing.';
                solveTextarea.focus();
                return;
            }

            const originalContent = analyzeButton.innerHTML;
            analyzeButton.disabled = true;
            analyzeButton.innerHTML = '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Analyzing rules…';
            if (problemValidation) problemValidation.textContent = '';
            analysisResult.hidden = false;
            analysisResult.textContent = 'Creating a deterministic ServiceDNA preview…';

            try {
                const response = await fetch(analyzerCard.dataset.previewUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    body: JSON.stringify({
                        description,
                        csrf_token: analyzerCard.dataset.csrfToken || '',
                    }),
                });
                const payload = await response.json();
                if (!response.ok) throw new Error(payload.error || 'ServiceDNA preview is unavailable.');

                const heading = document.createElement('h3');
                heading.className = 'h6';
                heading.textContent = 'Rule-based ServiceDNA preview';
                const note = document.createElement('p');
                note.className = 'small text-muted';
                note.textContent = 'This preview is not saved. Submit the request to continue through matching and provider assessment.';
                const grid = document.createElement('dl');
                grid.className = 'service-dna-preview-grid mb-0';
                const fields = [
                    ['Category', payload.category],
                    ['Problem type', payload.problem_type],
                    ['Affected item', payload.affected_entity],
                    ['Symptoms', payload.symptoms],
                    ['Context', payload.context],
                    ['Possible services', payload.possible_service_types],
                    ['Urgency signal', payload.urgency],
                    ['Rule confidence signal', `${payload.confidence_score}% (not diagnostic accuracy)`],
                ];
                fields.forEach(([label, value]) => {
                    const term = document.createElement('dt');
                    term.textContent = label;
                    const detail = document.createElement('dd');
                    detail.textContent = Array.isArray(value) ? (value.length ? value.join(', ') : 'None detected') : (value || 'Not detected');
                    grid.append(term, detail);
                });
                analysisResult.replaceChildren(heading, note, grid);
                analysisResult.classList.remove('is-error');
                analysisResult.classList.add('is-ready');
            } catch (error) {
                analysisResult.textContent = error instanceof Error ? error.message : 'ServiceDNA preview is unavailable. You can still start a request.';
                analysisResult.classList.remove('is-ready');
                analysisResult.classList.add('is-error');
            } finally {
                analyzeButton.disabled = false;
                analyzeButton.innerHTML = originalContent;
            }
        });
    }

    if (solveSubmitBtn && solveTextarea) {
        solveSubmitBtn.addEventListener('click', (e) => {
            const problem = solveTextarea.value.trim();
            if (problem.length < 20) {
                e.preventDefault();
                const validation = document.querySelector('#problemValidation');
                if (validation) validation.textContent = 'Describe the problem in at least 20 characters to continue.';
                solveTextarea.focus();
                return;
            }
            const targetUrl = new URL(solveSubmitBtn.getAttribute('href') || 'create_request.php', window.location.href);
            if (targetUrl.pathname.endsWith('/login.php')) {
                targetUrl.searchParams.set('problem', problem);
            } else if (targetUrl.pathname.endsWith('/create_request.php')) {
                targetUrl.searchParams.set('description', problem);
            } else {
                return;
            }
            window.location.href = targetUrl.toString();
            e.preventDefault();
        });
    }

    // -------------------------------------------------------------------------
    // 2. Request Form Description Character Counter & Validation
    // -------------------------------------------------------------------------
    const requestDesc = document.querySelector('#description');
    const requestCount = document.querySelector('#requestDescriptionCount');

    if (requestDesc && requestCount) {
        const updateRequestCounter = () => {
            const len = requestDesc.value.length;
            requestCount.textContent = `${len} / 5000`;
            requestCount.classList.toggle('text-danger', len > 5000 || (len > 0 && len < 20));
            requestCount.classList.toggle('text-success', len >= 20 && len <= 5000);
        };
        requestDesc.addEventListener('input', updateRequestCounter);
        updateRequestCounter();
    }

    // -------------------------------------------------------------------------
    // 3. Multi-Image Upload File Validation & Feedback
    // -------------------------------------------------------------------------
    const requestImages = document.querySelector('#images');
    const requestImageHint = document.querySelector('#requestImageHint');

    if (requestImages && requestImageHint) {
        requestImages.addEventListener('change', () => {
            const files = Array.from(requestImages.files || []);
            const allowed = ['image/jpeg', 'image/png', 'image/webp'];
            const invalid = files.some(file => !allowed.includes(file.type) || file.size > 5 * 1024 * 1024);

            requestImageHint.textContent = `${files.length} image${files.length === 1 ? '' : 's'} selected`;
            requestImageHint.classList.toggle('text-danger', files.length > 5 || invalid);
            if (files.length > 5) {
                requestImageHint.textContent += ' (maximum 5 images allowed)';
            } else if (invalid) {
                requestImageHint.textContent += ' (only JPG, PNG, WEBP under 5MB each allowed)';
            }
        });
    }

    // -------------------------------------------------------------------------
    // 4. Form Submission Button Loading States
    // -------------------------------------------------------------------------
    const confirmDialog = document.createElement('dialog');
    confirmDialog.className = 'serveiq-confirm-dialog';
    confirmDialog.innerHTML = `
        <form method="dialog" class="serveiq-confirm-content">
            <span class="confirm-dialog-icon"><i class="bi bi-exclamation-circle" aria-hidden="true"></i></span>
            <h2 class="h5" id="serveiqConfirmTitle">Please confirm</h2>
            <p class="text-muted" id="serveiqConfirmMessage"></p>
            <div class="d-flex justify-content-end gap-2 mt-4">
                <button type="button" class="btn btn-outline-secondary" data-confirm-cancel>Go back</button>
                <button type="button" class="btn btn-danger" data-confirm-accept>Continue</button>
            </div>
        </form>`;
    document.body.append(confirmDialog);
    let pendingForm = null;
    let pendingSubmitter = null;
    const cancelConfirm = confirmDialog.querySelector('[data-confirm-cancel]');
    const acceptConfirm = confirmDialog.querySelector('[data-confirm-accept]');
    cancelConfirm.addEventListener('click', () => confirmDialog.close());
    acceptConfirm.addEventListener('click', () => {
        const form = pendingForm;
        const submitter = pendingSubmitter;
        pendingForm = null;
        pendingSubmitter = null;
        confirmDialog.close();
        if (!form) return;
        form.dataset.confirmApproved = 'true';
        if (submitter && submitter.isConnected) form.requestSubmit(submitter);
        else form.requestSubmit();
    });

    document.querySelectorAll('form[data-confirm]').forEach(form => {
        form.addEventListener('submit', event => {
            if (form.dataset.confirmApproved === 'true') {
                delete form.dataset.confirmApproved;
                return;
            }
            event.preventDefault();
            event.stopImmediatePropagation();
            pendingForm = form;
            pendingSubmitter = event.submitter || null;
            confirmDialog.querySelector('#serveiqConfirmMessage').textContent = form.dataset.confirm || 'Continue with this action?';
            confirmDialog.showModal();
        });
    });

    const forms = document.querySelectorAll('form:not([data-no-loading])');
    forms.forEach(form => {
        form.addEventListener('submit', (event) => {
            if (form.checkValidity && form.checkValidity()) {
                const submitBtn = form.querySelector('button[type="submit"]');
                if (submitBtn && !submitBtn.disabled) {
                    submitBtn.disabled = true;
                    const originalText = submitBtn.innerHTML;
                    submitBtn.setAttribute('data-original-text', originalText);
                    submitBtn.innerHTML = `<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span> Processing...`;
                }
            } else if (form.checkValidity) {
                event.preventDefault();
                form.reportValidity();
            }
        });
    });

    // -------------------------------------------------------------------------
    // 5. Interactive Star Rating Selector
    // -------------------------------------------------------------------------
    const starPicker = document.querySelector('.star-picker');
    const starRatingText = document.querySelector('#starRatingText');
    if (starPicker) {
        const starLabels = {
            1: '1 Star — Poor Experience',
            2: '2 Stars — Below Average',
            3: '3 Stars — Average Service',
            4: '4 Stars — Good & Reliable',
            5: '5 Stars — Excellent & Recommended',
        };
        const inputs = starPicker.querySelectorAll('input[type="radio"]');
        inputs.forEach(input => {
            input.addEventListener('change', () => {
                if (starRatingText && starLabels[input.value]) {
                    starRatingText.textContent = starLabels[input.value];
                }
            });
        });
    }

    document.querySelectorAll('[data-use-location]').forEach(button => {
        button.addEventListener('click', () => {
            const form = button.closest('form');
            const latitude = form?.querySelector('[name="latitude"]');
            const longitude = form?.querySelector('[name="longitude"]');
            const status = form?.querySelector('[data-location-status]');
            if (!latitude || !longitude || !status) return;
            if (!navigator.geolocation) {
                status.textContent = 'Location is not supported by this browser.';
                return;
            }
            button.disabled = true;
            status.textContent = 'Waiting for location permission…';
            navigator.geolocation.getCurrentPosition(position => {
                latitude.value = position.coords.latitude.toFixed(7);
                longitude.value = position.coords.longitude.toFixed(7);
                status.textContent = 'Location saved for distance estimates.';
                button.disabled = false;
            }, () => {
                status.textContent = 'Location unavailable. You can continue without distance filtering.';
                button.disabled = false;
            }, { enableHighAccuracy: false, timeout: 10000, maximumAge: 300000 });
        });
    });

    // -------------------------------------------------------------------------
    // 6. Smooth Navbar Scroll Backdrop
    // -------------------------------------------------------------------------
    const navbar = document.querySelector('.site-navbar');
    if (navbar) {
        const updateNav = () => {
            navbar.classList.toggle('navbar-scrolled', window.scrollY > 20);
        };
        window.addEventListener('scroll', updateNav, { passive: true });
        updateNav();
    }

    // Homepage photographs are optional and lazy-loaded only near view.
    const illustrationNodes = document.querySelectorAll('[data-image-url]');
    const loadedIllustrations = new Map();
    const revealIllustration = element => {
        const imageUrl = element.dataset.imageUrl;
        if (!imageUrl) return;
        const cached = loadedIllustrations.get(imageUrl);
        if (cached === true) {
            element.style.backgroundImage = `url("${imageUrl}")`;
            return;
        }
        if (cached === false) {
            element.classList.add('illustration-unavailable');
            return;
        }
        if (cached === 'loading') return;
        loadedIllustrations.set(imageUrl, 'loading');
        const image = new Image();
        image.onload = () => {
            loadedIllustrations.set(imageUrl, true);
            Array.from(illustrationNodes).filter(node => node.dataset.imageUrl === imageUrl).forEach(node => {
                node.style.backgroundImage = `url("${imageUrl}")`;
            });
        };
        image.onerror = () => {
            loadedIllustrations.set(imageUrl, false);
            Array.from(illustrationNodes).filter(node => node.dataset.imageUrl === imageUrl).forEach(node => node.classList.add('illustration-unavailable'));
        };
        image.src = imageUrl;
    };
    if ('IntersectionObserver' in window) {
        const illustrationObserver = new IntersectionObserver((entries, observer) => {
            entries.forEach(entry => {
                if (!entry.isIntersecting) return;
                revealIllustration(entry.target);
                observer.unobserve(entry.target);
            });
        }, { rootMargin: '220px 0px' });
        illustrationNodes.forEach(node => illustrationObserver.observe(node));
    } else {
        illustrationNodes.forEach(revealIllustration);
    }

    if ('IntersectionObserver' in window && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        const revealObserver = new IntersectionObserver((entries, observer) => {
            entries.forEach(entry => {
                if (!entry.isIntersecting) return;
                entry.target.classList.add('is-revealed');
                observer.unobserve(entry.target);
            });
        }, { threshold: 0.12, rootMargin: '0px 0px -35px 0px' });
        document.querySelectorAll('main > section:not(.product-hero), .dashboard-page > .container-fluid > section').forEach((section, index) => {
            section.classList.add('reveal-on-scroll');
            section.style.setProperty('--reveal-delay', `${Math.min(index % 3, 2) * 65}ms`);
            revealObserver.observe(section);
        });
    }

    const networkVisual = document.querySelector('.hero-network');
    if (networkVisual && window.matchMedia('(hover: hover) and (pointer: fine)').matches && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        let networkFrame = 0;
        networkVisual.addEventListener('pointermove', event => {
            if (networkFrame) return;
            const pointerX = event.clientX;
            const pointerY = event.clientY;
            networkFrame = requestAnimationFrame(() => {
                const bounds = networkVisual.getBoundingClientRect();
                const x = ((pointerX - bounds.left) / bounds.width - 0.5) * 3.6;
                const y = ((pointerY - bounds.top) / bounds.height - 0.5) * 2.4;
                networkVisual.style.setProperty('--network-x', `${x}px`);
                networkVisual.style.setProperty('--network-y', `${y}px`);
                networkFrame = 0;
            });
        }, { passive: true });
        networkVisual.addEventListener('pointerleave', () => {
            networkVisual.style.setProperty('--network-x', '0px');
            networkVisual.style.setProperty('--network-y', '0px');
        }, { passive: true });
    }

    // Desktop-only, canvas-based lime dust. A fixed pool cap keeps the effect
    // bounded even during long sessions; touch and reduced-motion users skip it.
    const finePointer = window.matchMedia('(hover: hover) and (pointer: fine)').matches;
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (finePointer && !reducedMotion) {
        const canvas = document.createElement('canvas');
        canvas.className = 'serveiq-cursor-canvas';
        canvas.setAttribute('aria-hidden', 'true');
        document.body.append(canvas);
        const context = canvas.getContext('2d', { alpha: true });
        const particles = [];
        const particleLimit = 56;
        const pointer = { x: -100, y: -100, active: false, strong: false };
        let pixelRatio = Math.min(window.devicePixelRatio || 1, 1.5);
        let frame = 0;
        let lastParticleAt = 0;
        let lastX = 0;
        let lastY = 0;
        let lastSparkAt = 0;
        const resizeCanvas = () => {
            pixelRatio = Math.min(window.devicePixelRatio || 1, 1.5);
            canvas.width = Math.round(window.innerWidth * pixelRatio);
            canvas.height = Math.round(window.innerHeight * pixelRatio);
            canvas.style.width = `${window.innerWidth}px`;
            canvas.style.height = `${window.innerHeight}px`;
            if (context) context.setTransform(pixelRatio, 0, 0, pixelRatio, 0, 0);
        };
        const addParticle = (x, y, burst = false) => {
            while (particles.length >= particleLimit) particles.shift();
            const angle = Math.random() * Math.PI * 2;
            const speed = burst ? 0.45 + Math.random() * 2.2 : 0.15 + Math.random() * 0.8;
            particles.push({
                x, y, vx: Math.cos(angle) * speed, vy: Math.sin(angle) * speed - (burst ? .3 : .12),
                size: burst ? 1 + Math.random() * 2.2 : .8 + Math.random() * 1.8,
                life: 0, duration: burst ? 360 + Math.random() * 220 : 280 + Math.random() * 260,
                alpha: .38 + Math.random() * .52, sparkle: Math.random() > .76,
            });
        };
        const addClickEffect = (type, x, y) => {
            while (particles.length >= particleLimit) particles.shift();
            particles.push({ type, x, y, life: 0, duration: type === 'flash' ? 150 : 480, alpha: .8 });
        };
        const draw = now => {
            frame = 0;
            if (!context) return;
            context.clearRect(0, 0, window.innerWidth, window.innerHeight);
            if (pointer.active) {
                const radius = pointer.strong ? 58 : 42;
                const glow = context.createRadialGradient(pointer.x, pointer.y, 0, pointer.x, pointer.y, radius);
                glow.addColorStop(0, `rgba(190, 255, 45, ${pointer.strong ? .19 : .12})`);
                glow.addColorStop(.28, 'rgba(157, 231, 31, .065)');
                glow.addColorStop(1, 'rgba(157, 231, 31, 0)');
                context.fillStyle = glow;
                context.beginPath();
                context.arc(pointer.x, pointer.y, radius, 0, Math.PI * 2);
                context.fill();
            }
            for (let index = particles.length - 1; index >= 0; index--) {
                const p = particles[index];
                p.life += 16.7;
                const progress = Math.min(1, p.life / p.duration);
                if (p.type === 'ripple') {
                    context.globalAlpha = .34 * (1 - progress);
                    context.strokeStyle = '#c6ff43';
                    context.lineWidth = 1.5 * (1 - progress * .45);
                    context.shadowBlur = 12;
                    context.shadowColor = '#baff32';
                    context.beginPath();
                    context.arc(p.x, p.y, 5 + progress * 28, 0, Math.PI * 2);
                    context.stroke();
                    if (progress >= 1) particles.splice(index, 1);
                    continue;
                }
                if (p.type === 'flash') {
                    const radius = 5 + progress * 17;
                    const flash = context.createRadialGradient(p.x, p.y, 0, p.x, p.y, radius);
                    flash.addColorStop(0, `rgba(220, 255, 151, ${.72 * (1 - progress)})`);
                    flash.addColorStop(.3, `rgba(194, 255, 53, ${.36 * (1 - progress)})`);
                    flash.addColorStop(1, 'rgba(194, 255, 53, 0)');
                    context.fillStyle = flash;
                    context.beginPath();
                    context.arc(p.x, p.y, radius, 0, Math.PI * 2);
                    context.fill();
                    if (progress >= 1) particles.splice(index, 1);
                    continue;
                }
                p.x += p.vx;
                p.y += p.vy;
                p.vx *= .985;
                p.vy = p.vy * .985 + .008;
                const alpha = p.alpha * (1 - progress);
                const size = p.size * (1 - progress * .72);
                context.globalAlpha = alpha;
                context.shadowBlur = p.sparkle ? 12 : 8;
                context.shadowColor = '#baff32';
                context.fillStyle = '#c6ff43';
                context.beginPath();
                context.arc(p.x, p.y, size, 0, Math.PI * 2);
                context.fill();
                if (p.sparkle && progress < .62) {
                    context.globalAlpha = alpha * .55;
                    context.fillRect(p.x - size * 2.1, p.y - .35, size * 4.2, .7);
                    context.fillRect(p.x - .35, p.y - size * 2.1, .7, size * 4.2);
                }
                if (progress >= 1) particles.splice(index, 1);
            }
            context.globalAlpha = 1;
            context.shadowBlur = 0;
            if (particles.length) frame = requestAnimationFrame(draw);
        };
        const ensureFrame = () => { if (!frame) frame = requestAnimationFrame(draw); };
        resizeCanvas();
        window.addEventListener('resize', resizeCanvas, { passive: true });
        document.addEventListener('pointermove', event => {
            pointer.x = event.clientX;
            pointer.y = event.clientY;
            pointer.active = true;
            pointer.strong = Boolean(event.target.closest('.btn-primary, .problem-card .btn-dark'));
            const now = performance.now();
            const distance = Math.hypot(pointer.x - lastX, pointer.y - lastY);
            if (distance > 3 && now - lastParticleAt > 18) {
                lastParticleAt = now;
                lastX = pointer.x;
                lastY = pointer.y;
                addParticle(pointer.x, pointer.y);
                if (Math.random() > .72 && now - lastSparkAt > 95) {
                    lastSparkAt = now;
                    addParticle(pointer.x + (Math.random() - .5) * 9, pointer.y + (Math.random() - .5) * 9);
                }
            }
            ensureFrame();
        }, { passive: true });
        document.addEventListener('pointerleave', () => {
            pointer.active = false;
            pointer.strong = false;
            ensureFrame();
        });
        document.addEventListener('pointerdown', event => {
            if (event.button !== 0 || event.target.closest('input, textarea, select, [contenteditable="true"]')) return;
            addClickEffect('flash', event.clientX, event.clientY);
            addClickEffect('ripple', event.clientX, event.clientY);
            for (let i = 0; i < 8; i++) addParticle(event.clientX, event.clientY, true);
            ensureFrame();
        }, { passive: true });
    }
});
