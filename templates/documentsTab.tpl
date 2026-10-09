{**
 * plugins/generic/publisherApp/templates/documentsTab.tpl
 *
 * The documents tab on the submission page, read live from the app. The journal chooses in the app
 * what shows here (invoices with a Pay button, receipts, the LOA, certificates) and the tab is
 * called "Payment & documents" or "LOA & Certificate" after it. Authors and editors see the same.
 *}
<tab id="publisherAppDocuments" label="{$publisherAppDocuments.title|escape}">
	<div class="pkp_publisherApp" style="max-width:720px">
		{if $publisherAppDocuments.invoices}
			<h3 style="margin:0 0 .5rem">{translate key="plugins.generic.publisherApp.invoices"} · {$publisherAppDocuments.number|escape}</h3>
			<table class="pkpTable" style="width:100%;margin-bottom:1.5rem">
				{foreach from=$publisherAppDocuments.invoices item=invoice}
					<tr>
						<td>{$invoice.label|escape} <small>{$invoice.number|escape} · {$invoice.total|escape}</small></td>
						<td><strong style="color:{if $invoice.paid}#1b6440{else}#b3261e{/if}">{$invoice.status|escape}</strong></td>
						<td style="text-align:right;white-space:nowrap">
							{if $invoice.pay_url}
								<a class="pkpButton" href="{$invoice.pay_url|escape}" target="_blank" rel="noopener">{translate key="plugins.generic.publisherApp.pay"}</a>
							{/if}
							<a class="pkpButton" href="{$invoice.url|escape}" rel="noopener">{translate key="plugins.generic.publisherApp.download"}</a>
						</td>
					</tr>
				{/foreach}
			</table>
		{/if}

		{if $publisherAppDocuments.receipts}
			<h3 style="margin:0 0 .5rem">{translate key="plugins.generic.publisherApp.receipts"}</h3>
			<table class="pkpTable" style="width:100%;margin-bottom:1.5rem">
				{foreach from=$publisherAppDocuments.receipts item=receipt}
					<tr>
						<td>{$receipt.label|escape} <small>{$receipt.number|escape} · {$receipt.amount|escape}</small></td>
						<td style="text-align:right">
							<a class="pkpButton" href="{$receipt.url|escape}" rel="noopener">{translate key="plugins.generic.publisherApp.download"}</a>
						</td>
					</tr>
				{/foreach}
			</table>
		{/if}

		{if $publisherAppDocuments.loa}
			<h3 style="margin:0 0 .5rem">{translate key="plugins.generic.publisherApp.loa"}</h3>
			<table class="pkpTable" style="width:100%;margin-bottom:1.5rem">
				<tr>
					<td>{translate key="plugins.generic.publisherApp.loa"} <small>{$publisherAppDocuments.loa.number|escape}</small></td>
					<td style="text-align:right">
						<a class="pkpButton" href="{$publisherAppDocuments.loa.url|escape}" rel="noopener">{translate key="plugins.generic.publisherApp.download"}</a>
					</td>
				</tr>
			</table>
		{/if}

		{if $publisherAppShowsCertificates}
			<h3 style="margin:0 0 .5rem">{translate key="plugins.generic.publisherApp.certificate"}</h3>
			{if $publisherAppDocuments.certificates}
				<table class="pkpTable" style="width:100%">
					{foreach from=$publisherAppDocuments.certificates item=certificate}
						<tr>
							<td>{$certificate.author|escape} <small>{$certificate.number|escape}</small></td>
							<td style="text-align:right">
								<a class="pkpButton" href="{$certificate.url|escape}" rel="noopener">{translate key="plugins.generic.publisherApp.download"}</a>
							</td>
						</tr>
					{/foreach}
				</table>
			{else}
				<p>{translate key="plugins.generic.publisherApp.certificateWaiting"}</p>
			{/if}
		{/if}
	</div>
</tab>
