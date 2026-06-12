jQuery(document).ready(function ($) {

    // Bulk Image Generator
    $('#fgp_bulk_generate').on('click', function () {
        const btn = $(this);
        const start = $('#fgp_date_start').val();
        const end = $('#fgp_date_end').val();
        const statusDiv = $('#fgp_bulk_status');
        const log = $('#fgp-bulk-log');
        const progressBar = $('.progress-bar .progress');

        if (!start || !end) {
            alert('Seleziona entrambe le date.');
            return;
        }

        if (!confirm('Sei sicuro? Questo rigenererà le immagini per tutti gli articoli nel periodo selezionato.')) {
            return;
        }

        btn.prop('disabled', true);
        statusDiv.show();
        log.html('<div style="color: #4cd964;">⏳ Ricerca articoli...</div>');
        progressBar.css('width', '0%');

        // 1. Get Posts
        $.post(fgp_vars.ajax_url, {
            action: 'fgp_get_posts_by_date',
            start: start,
            end: end,
            _wpnonce: fgp_vars.nonce
        }, function (res) {
            if (!res.success) {
                alert('Errore: ' + res.data);
                btn.prop('disabled', false);
                return;
            }

            const posts = res.data;
            const total = posts.length;

            if (total === 0) {
                log.html('<div style="color: #ff6b6b;">✗ Nessun articolo trovato in questo periodo.</div>');
                btn.prop('disabled', false);
                return;
            }

            log.html('<div style="color: #4cd964;">✓ Trovati ' + total + ' articoli. Inizio rigenerazione...</div>');

            let processed = 0;

            // 2. Process Queue
            function processNext() {
                if (posts.length === 0) {
                    log.append('<div style="color: #4cd964; font-weight: bold; margin-top: 12px; padding-top: 12px; border-top: 1px solid #4cd964;">✓ Completato! Tutte le immagini sono state rigenerate.</div>');
                    log.scrollTop(log[0].scrollHeight);
                    btn.prop('disabled', false);
                    return;
                }

                const postId = posts.shift(); // Get next ID

                $.post(fgp_vars.ajax_url, {
                    action: 'fgp_regenerate_single_image',
                    post_id: postId,
                    _wpnonce: fgp_vars.nonce
                }, function (r) {
                    processed++;
                    const pct = Math.round((processed / total) * 100);
                    progressBar.css('width', pct + '%').text(pct + '%');

                    if (r.success) {
                        log.append('<div style="color: #4cd964;">[' + processed + '/' + total + '] ID ' + postId + ': ✓ OK</div>');
                    } else {
                        log.append('<div style="color: #ff6b6b;">[' + processed + '/' + total + '] ID ' + postId + ': ✗ ' + r.data + '</div>');
                    }
                    log.scrollTop(log[0].scrollHeight);

                    processNext(); // Recursive call
                }).fail(function (xhr, status, error) {
                    processed++;
                    const pct = Math.round((processed / total) * 100);
                    progressBar.css('width', pct + '%').text(pct + '%');

                    // Tentativo di estrarre un messaggio di errore utile
                    let errMsg = 'Errore di rete';
                    if (xhr.status !== 200) {
                        errMsg = 'HTTP ' + xhr.status + ' ' + xhr.statusText;
                    }
                    if (xhr.responseText) {
                        // Se è un errore PHP stampato, prendiamo i primi 100 caratteri
                        errMsg += ' - ' + xhr.responseText.substring(0, 100);
                    }

                    log.append('<div style="color: #ff6b6b;">[' + processed + '/' + total + '] ID ' + postId + ': ✗ ' + errMsg + '</div>');
                    log.scrollTop(log[0].scrollHeight);
                    console.error('ID ' + postId + ' Failed:', xhr);
                    processNext();
                });
            }

            processNext();
        });
    });

});