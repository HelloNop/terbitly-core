{**
 * plugins/generic/publisherApp/templates/documentsTab.tpl
 *
 * "LOA & Certificate" tab on the submission page, read live from the app once the LOA is issued.
 * Authors and editors see the same: the LOA and the authors' certificates as PDFs.
 *}
<tab id="publisherAppDocuments" label="{translate key="plugins.generic.publisherApp.tab"}">
	<div class="pkp_publisherApp" style="max-width:720px">
		<h3 style="margin:0 0 .5rem">{translate key="plugins.generic.publisherApp.loa"} · {$publisherAppDocuments.number|escape}</h3>
		<table class="pkpTable" style="width:100%">
			<tr>
				<td>{translate key="plugins.generic.publisherApp.loa"} <small>{$publisherAppDocuments.loa.number|escape}</small></td>
				<td style="text-align:right">
					<a class="pkpButton" href="{$publisherAppDocuments.loa.url|escape}" rel="noopener">{translate key="plugins.generic.publisherApp.download"}</a>
				</td>
			</tr>
		</table>

		<h3 style="margin:1.5rem 0 .5rem">{translate key="plugins.generic.publisherApp.certificate"}</h3>
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
	</div>
</tab>
