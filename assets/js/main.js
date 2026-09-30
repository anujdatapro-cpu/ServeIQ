/**
 * ServeIQ — Modern SaaS Client-side Interactions
 * Phase 1-10 Integrated Micro-interactions
 */

document.addEventListener('DOMContentLoaded', () => {
    // -------------------------------------------------------------------------
    // 1. Problem Solver & Quick Chips (Customer Experience)
    // -------------------------------------------------------------------------
    const solveTextarea = document.querySelector('#solveTextarea, #problemDescription');
    const solveSubmitBtn = document.querySelector('#solveSubmitBtn');
    const solveCharCount = document.querySelector('#solveCharCount, #characterCount');
    const quickChips = document.querySelectorAll('.quick-chip');

    if (solveTextarea) {
        const updateSolveCount = () => {
            const length = solveTextarea.value.length;
            if (solveCharCount) {
                solveCharCount.textContent = `${length} / 1000`;
            }
            if (solveSubmitBtn) {
                // Enabled if user has typed something
                solveSubmitBtn.classList.toggle('disabled', length < 10);
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

    if (solveSubmitBtn && solveTextarea) {
        solveSubmitBtn.addEventListener('click', (e) => {
            const problem = solveTextarea.value.trim();
            if (problem.length < 10) {
                e.preventDefault();
                solveTextarea.focus();
                return;
            }
            const targetUrl = new URL(solveSubmitBtn.getAttribute('href') || 'create_request.php', window.location.href);
            targetUrl.searchParams.set('description', problem);
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
    const forms = document.querySelectorAll('form:not([data-no-loading])');
    forms.forEach(form => {
        form.addEventListener('submit', () => {
            if (form.checkValidity && form.checkValidity()) {
                const submitBtn = form.querySelector('button[type="submit"]');
                if (submitBtn && !submitBtn.disabled) {
                    submitBtn.disabled = true;
                    const originalText = submitBtn.innerHTML;
                    submitBtn.setAttribute('data-original-text', originalText);
                    submitBtn.innerHTML = `<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span> Processing...`;
                }
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
});