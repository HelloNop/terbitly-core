/**
 * @file plugins/generic/terbitlyCore/js/documents.js
 *
 * The documents the journal chose in Terbitly (invoices with a Pay button, receipts, the LOA,
 * certificates) as an item of the 3.5 workflow menu. Each submission's documents are asked once
 * from the plugin; the item shows when the first of them exists, named as the app names it.
 */
(function () {
	const {h, reactive} = pkp.modules.vue;
	// Submission id → {title, html}, null while asking, false when there is nothing to show.
	const documents = reactive({});

	function load(id) {
		if (id in documents) {
			return;
		}
		documents[id] = null;
		fetch(window.terbitlyCoreDocumentsUrl.replace('__id__', id), {credentials: 'same-origin'})
			.then((response) => (response.ok ? response.json() : null))
			.then((data) => {
				documents[id] = data && data.html ? data : false;
			})
			.catch(() => {
				documents[id] = false;
			});
	}

	// The html is rendered by the plugin from escaped values.
	pkp.registry.registerComponent('TerbitlyCoreDocuments', {
		props: {submissionId: {type: Number, required: true}},
		render() {
			const data = documents[this.submissionId];
			return h('div', {class: 'p-4', innerHTML: data ? data.html : ''});
		},
	});

	pkp.registry.storeExtendFn('workflow', 'getMenuItems', (items, args) => {
		if (!args || !args.submission) {
			return items;
		}
		load(args.submission.id);
		const data = documents[args.submission.id];
		return data
			? [...items, {key: 'terbitlyCore', label: data.title, state: {primaryMenuItem: 'terbitlyCore'}}]
			: items;
	});

	pkp.registry.storeExtendFn('workflow', 'getPrimaryItems', (items, args) =>
		args && args.selectedMenuState && args.selectedMenuState.primaryMenuItem === 'terbitlyCore' && args.submission
			? [{component: 'TerbitlyCoreDocuments', props: {submissionId: args.submission.id}}]
			: items,
	);
})();
