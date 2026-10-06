{**
 * plugins/generic/publisherApp/templates/paymentsTab.tpl
 *
 * "Payments" tab on the submission page, read live from the app. Authors get the pay links and
 * documents; editors only see whether each invoice is paid.
 *}
<tab id="publisherAppPayments" label="{translate key="plugins.generic.publisherApp.tab"}">
	<div class="pkp_publisherApp" style="max-width:720px">
		{if !$publisherAppStatus}
			<p>{translate key="plugins.generic.publisherApp.unavailable"}</p>
		{elseif !$publisherAppStatus.found}
			<p>{translate key="plugins.generic.publisherApp.notInApp"}</p>
		{else}
			<h3 style="margin:0 0 .5rem">{translate key="plugins.generic.publisherApp.invoices"} · {$publisherAppStatus.number|escape}</h3>
			{if $publisherAppStatus.invoices}
				<table class="pkpTable" style="width:100%">
					{foreach from=$publisherAppStatus.invoices item=invoice}
						<tr>
							<td>{$invoice.label|escape}{if $invoice.number} <small>{$invoice.number|escape}</small>{/if}</td>
							<td>{$invoice.amount|escape}</td>
							<td><strong>{$invoice.status|escape}</strong></td>
							<td style="text-align:right">
								{if $publisherAppForAuthor && $invoice.pay_url && $invoice.status != 'Paid'}
									<a class="pkpButton" href="{$invoice.pay_url|escape}" target="_blank" rel="noopener">{translate key="plugins.generic.publisherApp.pay"}</a>
								{/if}
							</td>
						</tr>
					{/foreach}
				</table>
			{else}
				<p>{translate key="plugins.generic.publisherApp.noInvoices"}</p>
			{/if}

			{if $publisherAppForAuthor}
				<h3 style="margin:1.5rem 0 .5rem">{translate key="plugins.generic.publisherApp.documents"}</h3>
				{if $publisherAppStatus.documents}
					<ul>
						{foreach from=$publisherAppStatus.documents item=document}
							<li><a href="{$document.url|escape}" target="_blank" rel="noopener">{$document.label|escape}</a> <small>{$document.number|escape}</small></li>
						{/foreach}
					</ul>
				{else}
					<p>{translate key="plugins.generic.publisherApp.noDocuments"}</p>
				{/if}
			{/if}
		{/if}
	</div>
</tab>
