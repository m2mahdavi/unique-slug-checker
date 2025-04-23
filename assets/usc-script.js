jQuery(document).ready(function ($) {
    const selectors = [
        '#post_name',
        '#editable-post-name',
        '#yoast-google-preview-slug-metabox',
        '#snippet-editor-slug',
        'input.rmp-slug-edit',
        '#aioseo-snippet-editor-slug',
        '#aioseo-post-slug',
        '#new-post-slug',
        '#yoast-google-preview-slug-metabox',
        '#rank-math-edit-snippet-slug'
    ];

    function checkSlug(slug, field) {
        if (!slug) return;

        const post_id = $('#post_ID').val() || 0;
        const $container = $('<div class="usc-feedback"></div>').insertAfter(field).empty().text(usc_ajax.checking);

        $.post(usc_ajax.ajax_url, {
            action: 'usc_check_slug',
            nonce: usc_ajax.nonce,
            slug: slug,
            post_id: post_id
        }, function (response) {
            if (response.success) {
                $container.empty();
                if (response.data.exists) {
                    $container.append(
                        $('<div class="usc-warning"></div>')
                            .html(`${usc_ajax.used}<br><a href="${response.data.permalink}" target="_blank">${response.data.post_title}</a>`)
                    );
                } else {
                    $container.append(
                        $('<div class="usc-success"></div>').text(usc_ajax.available)
                    );
                }
            } else {
                $container.text(usc_ajax.invalid);
            }
        });
    }

    function attachChecker(selector) {
        const $field = $(selector);
        if ($field.length && !$field.data('usc-attached')) {
            $field.data('usc-attached', true);
            $field.on('change blur', function () {
                checkSlug($(this).val(), $field);
            });
            // Trigger once on load
            if ($field.val()) {
                checkSlug($field.val(), $field);
            }
        }
    }

    // Watch DOM changes in case SEO plugins load fields dynamically
    const observer = new MutationObserver(() => {
        selectors.forEach(attachChecker);
    });
    observer.observe(document.body, { childList: true, subtree: true });

    // Initial attach
    selectors.forEach(attachChecker);
});
