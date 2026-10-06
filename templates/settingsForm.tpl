{**
 * plugins/generic/publisherApp/templates/settingsForm.tpl
 *
 * The app address and this journal's token.
 *}
<script>
	$(function() {ldelim}
		$('#publisherAppSettingsForm').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');
	{rdelim});
</script>

<form class="pkp_form" id="publisherAppSettingsForm" method="post" action="{url router=$smarty.const.ROUTE_COMPONENT op="manage" category="generic" plugin=$pluginName verb="settings" save=true}">
	{csrf}
	{include file="controllers/notification/inPlaceNotification.tpl" notificationId="publisherAppSettingsFormNotification"}

	<p>{translate key="plugins.generic.publisherApp.settings.description"}</p>

	{fbvFormArea id="publisherAppSettingsFormArea"}
		{fbvElement type="text" id="appUrl" value=$appUrl label="plugins.generic.publisherApp.settings.appUrl" required=true}
		{fbvElement type="text" password=true id="token" value=$token label="plugins.generic.publisherApp.settings.token" required=true}
	{/fbvFormArea}

	{fbvFormButtons}
</form>
