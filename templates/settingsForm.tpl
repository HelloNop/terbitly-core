{**
 * plugins/generic/terbitlyCore/templates/settingsForm.tpl
 *
 * The app address and this journal's token.
 *}
<script>
	$(function() {ldelim}
		$('#terbitlyCoreSettingsForm').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');
	{rdelim});
</script>

<form class="pkp_form" id="terbitlyCoreSettingsForm" method="post" action="{url router=$smarty.const.ROUTE_COMPONENT op="manage" category="generic" plugin=$pluginName verb="settings" save=true}">
	{csrf}
	{include file="controllers/notification/inPlaceNotification.tpl" notificationId="terbitlyCoreSettingsFormNotification"}

	<p>{translate key="plugins.generic.terbitlyCore.settings.description"}</p>

	{fbvFormArea id="terbitlyCoreSettingsFormArea"}
		{fbvElement type="text" id="appUrl" value=$appUrl label="plugins.generic.terbitlyCore.settings.appUrl" required=true}
		{fbvElement type="text" password=true id="token" value=$token label="plugins.generic.terbitlyCore.settings.token" required=true}
	{/fbvFormArea}

	{fbvFormButtons}
</form>
