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

    // A small desktop cursor accent and click glint. Decorative elements never
    // receive pointer events and are omitted on touch devices or reduced motion.
    const finePointer = window.matchMedia('(hover: hover) and (pointer: fine)').matches;
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (finePointer && !reducedMotion) {
        const cursor = document.createElement('span');
        cursor.className = 'serveiq-cursor-glow';
        cursor.setAttribute('aria-hidden', 'true');
        document.body.append(cursor);
        let frame = 0;
        let x = 0;
        let y = 0;
        let lastParticleAt = 0;
        document.addEventListener('pointermove', event => {
            x = event.clientX;
            y = event.clientY;
            const now = performance.now();
            if (now - lastParticleAt > 85) {
                lastParticleAt = now;
                const particle = document.createElement('span');
                particle.className = 'serveiq-cursor-particle';
                particle.setAttribute('aria-hidden', 'true');
                particle.style.left = `${x}px`;
                particle.style.top = `${y}px`;
                document.body.append(particle);
                window.setTimeout(() => particle.remove(), 380);
            }
            if (frame) return;
            frame = requestAnimationFrame(() => {
                cursor.style.transform = `translate3d(${x}px, ${y}px, 0)`;
                cursor.classList.add('is-visible');
                frame = 0;
            });
        }, { passive: true });
        document.addEventListener('pointerdown', event => {
            if (event.button !== 0 || event.target.closest('input, textarea, select, [contenteditable="true"]')) return;
            const sparkle = document.createElement('span');
            sparkle.className = 'serveiq-click-sparkle';
            sparkle.setAttribute('aria-hidden', 'true');
            sparkle.style.left = `${event.clientX}px`;
            sparkle.style.top = `${event.clientY}px`;
            document.body.append(sparkle);
            window.setTimeout(() => sparkle.remove(), 520);
        }, { passive: true });
    }
});
