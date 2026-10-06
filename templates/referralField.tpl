{**
 * plugins/generic/publisherApp/templates/referralField.tpl
 *
 * Step 3 of the submission wizard. Optional; it never changes the review or the fees.
 *}
{fbvFormSection label="plugins.generic.publisherApp.referral.label" description="plugins.generic.publisherApp.referral.description"}
	{fbvElement type="text" id="publisherAppReferral" value=$publisherAppReferral maxlength="32" size=$fbvStyles.size.SMALL}
{/fbvFormSection}
