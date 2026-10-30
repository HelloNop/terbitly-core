{**
 * plugins/generic/terbitlyCore/templates/referralField.tpl
 *
 * Step 3 of the submission wizard. Optional; it never changes the review or the fees.
 *}
{fbvFormSection label="plugins.generic.terbitlyCore.referral.label" description="plugins.generic.terbitlyCore.referral.description"}
	{fbvElement type="text" id="terbitlyCoreReferral" value=$terbitlyCoreReferral maxlength="32" size=$fbvStyles.size.SMALL}
{/fbvFormSection}
