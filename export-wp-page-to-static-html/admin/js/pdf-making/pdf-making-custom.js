window.addEventListener('load', function () {
    // Check if jQuery is available to monitor AJAX
    if (typeof jQuery !== 'undefined') {
        let ajaxPending = false;

        // Track ongoing AJAX calls
        jQuery(document).ajaxStart(function () {
            ajaxPending = true;
        });

        jQuery(document).ajaxStop(function () {
            ajaxPending = false;

            // Delay slightly to ensure final rendering
            setTimeout(() => {
                triggerPDFDownload();
            }, 200); 
        });

        // Fallback: if no AJAX fires at all, still trigger after short delay
        setTimeout(() => {
            if (!ajaxPending) {
                triggerPDFDownload();
            }
        }, 1000);

    } else {
        // No jQuery = no AJAX to track = just trigger
        triggerPDFDownload();
    }
});

function triggerPDFDownload() {
    const element = document.getElementById("page");
    if (!element) return;

    html2pdf().set({
        margin: 10,
        filename: EWPPTSH_WP_PageData.current_page + '.pdf',
        image: { type: 'jpeg', quality: 0.99 },
        html2canvas: { scale: 2, useCORS: true },
        jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' }
    })
    .from(element)
    .save()
    .then(() => {
        updatePdfDownloadCount();
        
        const modal = document.getElementById('pdf-download-modal');
        if (modal) modal.style.display = 'none';
    });
}


function updatePdfDownloadCount() {
    fetch(rcewpp.ajax_url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: new URLSearchParams({
            action: 'ewpptsh_increment_pdf_count',
            'rc_nonce': rcewpp.nonce,
        }),
    });
}

