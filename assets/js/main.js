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
            analysisResult.textContent = 'Creating a deterministic Service Analysis preview…';

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
                if (!response.ok) throw new Error(payload.error || 'Service Analysis preview is unavailable.');

                const heading = document.createElement('h3');
                heading.className = 'h6';
                heading.textContent = 'Rule-based Service Analysis preview';
                const note = document.createElement('p');
                note.className = 'small text-muted';
                note.textContent = 'This preview is not saved. Submit the request to continue through matching and provider response.';
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
                analysisResult.textContent = error instanceof Error ? error.message : 'Service Analysis preview is unavailable. You can still start a request.';
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

    // -------------------------------------------------------------------------
    // 7. Premium Sparkling Cursor Trail & Starburst
    // -------------------------------------------------------------------------
    (() => {
        const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        const isTouchDevice = ('ontouchstart' in window) || (navigator.maxTouchPoints > 0);
        if (prefersReducedMotion || isTouchDevice) return;

        const canvas = document.createElement('canvas');
        canvas.id = 'serveiq-cursor-canvas';
        canvas.style.cssText = 'position:fixed;top:0;left:0;width:100vw;height:100vh;pointer-events:none;z-index:999999;';
        document.body.appendChild(canvas);

        const ctx = canvas.getContext('2d');
        if (!ctx) return;

        let width = canvas.width = window.innerWidth;
        let height = canvas.height = window.innerHeight;

        const updateCanvasSize = () => {
            width = canvas.width = window.innerWidth;
            height = canvas.height = window.innerHeight;
        };
        window.addEventListener('resize', updateCanvasSize, { passive: true });

        const particles = [];
        const MAX_PARTICLES = 300;
        const colors = ['#c2ff35', '#a3e635', '#10b981', '#ffffff', '#e4ff85'];

        let lastX = -1000;
        let lastY = -1000;

        const drawStar = (ctx, x, y, outerRadius, innerRadius) => {
            ctx.beginPath();
            for (let i = 0; i < 8; i++) {
                const radius = i % 2 === 0 ? outerRadius : innerRadius;
                const angle = (i * Math.PI) / 4;
                const px = x + Math.cos(angle) * radius;
                const py = y + Math.sin(angle) * radius;
                if (i === 0) ctx.moveTo(px, py);
                else ctx.lineTo(px, py);
            }
            ctx.closePath();
            ctx.fill();
        };

        const addParticle = (x, y, isBurst = false) => {
            if (particles.length >= MAX_PARTICLES) {
                particles.shift();
            }

            const color = colors[Math.floor(Math.random() * colors.length)];
            const isStar = Math.random() < (isBurst ? 0.35 : 0.18);
            const size = isBurst ? (Math.random() * 3.5 + 1.5) : (Math.random() * 2.5 + 0.8);
            const speedMultiplier = isBurst ? (Math.random() * 4 + 1.5) : (Math.random() * 0.8 + 0.2);
            const angle = Math.random() * Math.PI * 2;

            particles.push({
                x,
                y,
                vx: Math.cos(angle) * speedMultiplier + (isBurst ? 0 : (Math.random() - 0.5) * 0.5),
                vy: Math.sin(angle) * speedMultiplier + (isBurst ? 0 : (Math.random() - 0.5) * 0.5 + 0.2),
                size,
                maxSize: size,
                color,
                alpha: 1,
                decay: isBurst ? (Math.random() * 0.02 + 0.015) : (Math.random() * 0.012 + 0.008),
                isStar,
                rotation: Math.random() * Math.PI,
                vRot: (Math.random() - 0.5) * 0.05
            });
        };

        window.addEventListener('mousemove', (e) => {
            const currentX = e.clientX;
            const currentY = e.clientY;
            const dx = currentX - lastX;
            const dy = currentY - lastY;
            const dist = Math.hypot(dx, dy);

            if (dist > 3) {
                const steps = Math.min(Math.floor(dist / 4), 6);
                for (let i = 0; i <= steps; i++) {
                    const px = lastX + (dx * (i / steps));
                    const py = lastY + (dy * (i / steps));
                    addParticle(px, py, false);
                }
            }

            lastX = currentX;
            lastY = currentY;
        }, { passive: true });

        window.addEventListener('mousedown', (e) => {
            if (e.button === 0) {
                const count = Math.floor(Math.random() * 10) + 18;
                for (let i = 0; i < count; i++) {
                    addParticle(e.clientX, e.clientY, true);
                }
            }
        }, { passive: true });

        let animationFrameId = null;

        const render = () => {
            ctx.clearRect(0, 0, width, height);

            for (let i = particles.length - 1; i >= 0; i--) {
                const p = particles[i];
                p.x += p.vx;
                p.y += p.vy;
                p.vx *= 0.96;
                p.vy *= 0.96;
                p.alpha -= p.decay;
                p.rotation += p.vRot;

                if (p.alpha <= 0) {
                    particles.splice(i, 1);
                    continue;
                }

                ctx.save();
                ctx.globalAlpha = p.alpha;
                ctx.fillStyle = p.color;
                ctx.shadowColor = p.color;
                ctx.shadowBlur = p.isStar ? 6 : 3;

                if (p.isStar) {
                    drawStar(ctx, p.x, p.y, p.size * 1.8, p.size * 0.5);
                } else {
                    ctx.beginPath();
                    ctx.arc(p.x, p.y, p.size * (p.alpha), 0, Math.PI * 2);
                    ctx.fill();
                }
                ctx.restore();
            }

            if (!document.hidden) {
                animationFrameId = requestAnimationFrame(render);
            }
        };

        animationFrameId = requestAnimationFrame(render);

        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                if (animationFrameId) {
                    cancelAnimationFrame(animationFrameId);
                    animationFrameId = null;
                }
            } else {
                if (!animationFrameId) {
                    animationFrameId = requestAnimationFrame(render);
                }
            }
        });
    })();
});
