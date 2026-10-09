{**
 * plugins/generic/publisherApp/templates/fastTrackField.tpl
 *
 * Step 3 of the submission wizard, while the journal puts Fast track on the form in the Publisher App.
 * The price and days come from the app; the app bills it once the submission arrives.
 *}
{fbvFormSection title="plugins.generic.publisherApp.fastTrack.title"}
	{fbvFormSection list=true}
		{fbvElement type="checkbox" id="publisherAppFastTrack" value="1" checked=$publisherAppFastTrack label=$publisherAppFastTrackLabel translate=false}
	{/fbvFormSection}
	<p class="description">{$publisherAppFastTrackText|escape}</p>
{/fbvFormSection}
