{**
 * plugins/generic/terbitlyCore/templates/documentsTab.tpl
 *
 * The documents tab on the submission page, read live from the app. The journal chooses in the app
 * what shows here (invoices with a Pay button, receipts, the LOA, certificates) and the tab is
 * called "Payment & documents" or "LOA & Certificate" after it. Authors and editors see the same.
 *}
<tab id="terbitlyCoreDocuments" label="{$terbitlyCoreDocuments.title|escape}">
	<div class="pkp_terbitlyCore" style="max-width:720px">
		{if $terbitlyCoreDocuments.invoices}
			<h3 style="margin:0 0 .5rem">{translate key="plugins.generic.terbitlyCore.invoices"} · {$terbitlyCoreDocuments.number|escape}</h3>
			<table class="pkpTable" style="width:100%;margin-bottom:1.5rem">
				{foreach from=$terbitlyCoreDocuments.invoices item=invoice}
					<tr>
						<td>{$invoice.label|escape} <small>{$invoice.number|escape} · {$invoice.total|escape}</small></td>
						<td><strong style="color:{if $invoice.paid}#1b6440{else}#b3261e{/if}">{$invoice.status|escape}</strong></td>
						<td style="text-align:right;white-space:nowrap">
							{if $invoice.pay_url}
								<a class="pkpButton" href="{$invoice.pay_url|escape}" target="_blank" rel="noopener">{translate key="plugins.generic.terbitlyCore.pay"}</a>
							{/if}
							<a class="pkpButton" href="{$invoice.url|escape}" rel="noopener">{translate key="plugins.generic.terbitlyCore.download"}</a>
						</td>
					</tr>
				{/foreach}
			</table>
		{/if}

		{if $terbitlyCoreDocuments.receipts}
			<h3 style="margin:0 0 .5rem">{translate key="plugins.generic.terbitlyCore.receipts"}</h3>
			<table class="pkpTable" style="width:100%;margin-bottom:1.5rem">
				{foreach from=$terbitlyCoreDocuments.receipts item=receipt}
					<tr>
						<td>{$receipt.label|escape} <small>{$receipt.number|escape} · {$receipt.amount|escape}</small></td>
						<td style="text-align:right">
							<a class="pkpButton" href="{$receipt.url|escape}" rel="noopener">{translate key="plugins.generic.terbitlyCore.download"}</a>
						</td>
					</tr>
				{/foreach}
			</table>
		{/if}

		{if $terbitlyCoreDocuments.loa}
			<h3 style="margin:0 0 .5rem">{translate key="plugins.generic.terbitlyCore.loa"}</h3>
			<table class="pkpTable" style="width:100%;margin-bottom:1.5rem">
				<tr>
					<td>{translate key="plugins.generic.terbitlyCore.loa"} <small>{$terbitlyCoreDocuments.loa.number|escape}</small></td>
					<td style="text-align:right">
						<a class="pkpButton" href="{$terbitlyCoreDocuments.loa.url|escape}" rel="noopener">{translate key="plugins.generic.terbitlyCore.download"}</a>
					</td>
				</tr>
			</table>
		{/if}

		{if $terbitlyCoreShowsCertificates}
			<h3 style="margin:0 0 .5rem">{translate key="plugins.generic.terbitlyCore.certificate"}</h3>
			{if $terbitlyCoreDocuments.certificates}
				<table class="pkpTable" style="width:100%">
					{foreach from=$terbitlyCoreDocuments.certificates item=certificate}
						<tr>
							<td>{$certificate.author|escape} <small>{$certificate.number|escape}</small></td>
							<td style="text-align:right">
								<a class="pkpButton" href="{$certificate.url|escape}" rel="noopener">{translate key="plugins.generic.terbitlyCore.download"}</a>
							</td>
						</tr>
					{/foreach}
				</table>
			{else}
				<p>{translate key="plugins.generic.terbitlyCore.certificateWaiting"}</p>
			{/if}
		{/if}
	</div>
</tab>
