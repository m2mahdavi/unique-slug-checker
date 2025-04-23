( function ( wp ) {
	const { subscribe } = wp.data;
	const { select, dispatch } = wp.data;
	let lastSlug = null;
	let timeout = null;

	const checkSlug = (slug, postId) => {
		if (!slug || slug === lastSlug) return;

		lastSlug = slug;
		clearTimeout(timeout);

		const noticeId = 'usc-slug-notice';

		timeout = setTimeout(() => {
			dispatch('core/notices').createNotice(
				'info',
				usc_ajax.checking,
				{
					id: noticeId,
					isDismissible: false,
				}
			);

			wp.apiFetch({
				path: usc_ajax.ajax_url,
				method: 'POST',
				data: {
					action: 'usc_check_slug',
					nonce: usc_ajax.nonce,
					slug: slug,
					post_id: postId
				},
			}).then(response => {
				dispatch('core/notices').removeNotice(noticeId);
				if (!response.success || !response.data) return;

				const { exists, post_title, permalink } = response.data;

				if (exists) {
					dispatch('core/notices').createNotice(
						'error',
						usc_ajax.used + '\n' + post_title + '\n' + permalink ,
						{ isDismissible: true }
					);
				} else {
					dispatch('core/notices').createNotice(
						'success',
						usc_ajax.available,
						{ isDismissible: true }
					);
				}
			}).catch(() => {
				dispatch('core/notices').removeNotice(noticeId);
				dispatch('core/notices').createNotice(
					'error',
					usc_ajax.invalid,
					{ isDismissible: true }
				);
			});
		}, 800);
	};

	subscribe(() => {
		const post = select('core/editor').getCurrentPost();
		const slug = post.slug;
		const id = post.id;

		checkSlug(slug, id);
	});
})( window.wp );
