</main>

<footer class="app-footer-legal no-print">
    <div class="container-fluid">
        <div class="d-flex flex-column flex-sm-row justify-content-between align-items-center gap-2 py-3 small text-muted">
            <div>
                &copy; <?= date('Y') ?> TEAMProjekt Outsourcing GmbH
            </div>
            <div class="d-flex align-items-center gap-2">
                <a href="impressum.php" class="text-muted text-decoration-none fw-semibold">Impressum</a>
                <span>·</span>
                <a href="datenschutz.php" class="text-muted text-decoration-none fw-semibold">Datenschutz</a>
            </div>
        </div>
    </div>
</footer>

<style>
    .app-footer-legal {
        border-top: 1px solid rgba(148, 163, 184, .18);
        background: rgba(248, 250, 252, .92);
    }

    .app-footer-legal a:hover {
        color: #0d6efd !important;
        text-decoration: underline !important;
    }
</style>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.querySelectorAll('[data-confirm]').forEach(btn => {
    btn.addEventListener('click', function (e) {
        if (!confirm(this.dataset.confirm || 'Bist du sicher?')) {
            e.preventDefault();
        }
    });
});


function updateStepAccordionVisuals() {
    document.querySelectorAll('#stepsAccordion .claim-step-item').forEach(item => {
        const collapseEl = item.querySelector('.accordion-collapse');
        item.classList.toggle('is-open', !!collapseEl && collapseEl.classList.contains('show'));
    });
}

document.querySelectorAll('#stepsAccordion .accordion-collapse').forEach(collapseEl => {
    collapseEl.addEventListener('shown.bs.collapse', updateStepAccordionVisuals);
    collapseEl.addEventListener('hidden.bs.collapse', updateStepAccordionVisuals);
});

updateStepAccordionVisuals();

function openEightDStep(targetHash, shouldScroll = true) {
    if (!targetHash || !targetHash.startsWith('#step')) return;

    const collapseEl = document.querySelector(targetHash);
    if (!collapseEl || !collapseEl.classList.contains('accordion-collapse')) return;

    const collapse = bootstrap.Collapse.getOrCreateInstance(collapseEl, { toggle: false });
    collapse.show();

    if (shouldScroll) {
        const item = collapseEl.closest('.claim-step-item') || collapseEl.closest('.accordion-item') || collapseEl;
        setTimeout(() => {
            item.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }, 180);
    }
}

document.querySelectorAll('.eightd-step[href^="#step"]').forEach(link => {
    link.addEventListener('click', function (e) {
        e.preventDefault();
        const targetHash = this.getAttribute('href');
        openEightDStep(targetHash, true);

        if (history.pushState) {
            history.pushState(null, '', targetHash);
        } else {
            window.location.hash = targetHash;
        }
    });
});

if (window.location.hash && window.location.hash.startsWith('#step')) {
    window.addEventListener('load', () => {
        openEightDStep(window.location.hash, true);
    });
}
</script>
</body>
</html>
