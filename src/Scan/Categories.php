<?php
/**
 * Rule code → finding category (spec §7.7).
 *
 * @package Upshift
 */

namespace Upshift\Scan;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Heuristic category mapping from PHPCS rule codes.
 */
class Categories {

	const REMOVED            = 'removed';
	const NEEDS_EXTENSION    = 'needs_extension';
	const DEPRECATED         = 'deprecated';
	const CHANGED            = 'changed';
	const NEWER_THAN_CURRENT = 'newer_than_current';
	const ADVISORY           = 'advisory';
	const IGNORED            = 'ignored';

	/**
	 * Sniffs named New* that report a changed default or behaviour, not a new feature → changed (DECISIONS D37, P1).
	 */
	const CHANGED_SNIFFS = array(
		'NewHTMLEntitiesEncodingDefault',
		'NewIDNVariantDefault',
		'NewIconvMbstringCharsetDefault',
		'NewPasswordAlgoConstantValues',
	);

	/**
	 * Deprecation-only codes from Removed* sniffs whose code has no "Deprecated" in it → deprecated (D37, P2).
	 * Sniff.code, read from the sniff source (PHPCompatibility 10.0.0-alpha2).
	 */
	const DEPRECATED_CODES = array(
		'RemovedGetClassNoArgs.ArgIncorrect',
		'RemovedGetClassNoArgs.ArgMissing',
		'RemovedMbCheckEncodingNoArgs.Found',
	);

	/**
	 * Codes that break nothing by themselves (advice, heuristics, ini-dependent) → advisory, not counted (D37, P3/P4).
	 */
	const ADVISORY_CODES = array(
		'ReservedFunctionNames.FunctionDoubleUnderscore',
		'ReservedFunctionNames.MethodDoubleUnderscore',
		'Internal.NoCodeFound',
		'RemovedAlternativePHPTags.MaybeASPOpenTagFound',
		// Quote handling in htmlspecialchars() and friends changed in 8.1; it rarely breaks anything (DECISIONS D56).
		'NewHTMLEntitiesFlagsDefault.NotSet',
	);

	/**
	 * Functions, constants and classes of extensions PHP moved out of core: xmlrpc in 8.0; imap, oci8 and pspell in 8.4
	 * (DECISIONS D55). The site keeps working if the host installs the extension, so they are not "will break".
	 * Sniff.code, generated from the sniff tables of PHPCompatibility 10.0.0-alpha2; tests/internal-check.php compares
	 * this list with those tables. pdo_oci has no entries there.
	 */
	const NEEDS_EXTENSION_CODES = array(
		'RemovedClasses.imap_connectionRemoved',
		'RemovedClasses.ocicollectionRemoved',
		'RemovedClasses.ocilobRemoved',
		'RemovedClasses.pspell_configRemoved',
		'RemovedClasses.pspell_dictionaryRemoved',
		'RemovedClasses.xmlrpcserverRemoved',
		'RemovedConstants.cl_expungeRemoved',
		'RemovedConstants.cp_moveRemoved',
		'RemovedConstants.cp_uidRemoved',
		'RemovedConstants.enc7bitRemoved',
		'RemovedConstants.enc8bitRemoved',
		'RemovedConstants.encbase64Removed',
		'RemovedConstants.encbinaryRemoved',
		'RemovedConstants.encotherRemoved',
		'RemovedConstants.encquotedprintableRemoved',
		'RemovedConstants.ft_internalRemoved',
		'RemovedConstants.ft_notRemoved',
		'RemovedConstants.ft_peekRemoved',
		'RemovedConstants.ft_prefetchtextRemoved',
		'RemovedConstants.ft_uidRemoved',
		'RemovedConstants.imap_closetimeoutRemoved',
		'RemovedConstants.imap_gc_eltRemoved',
		'RemovedConstants.imap_gc_envRemoved',
		'RemovedConstants.imap_gc_textsRemoved',
		'RemovedConstants.imap_opentimeoutRemoved',
		'RemovedConstants.imap_readtimeoutRemoved',
		'RemovedConstants.imap_writetimeoutRemoved',
		'RemovedConstants.latt_haschildrenRemoved',
		'RemovedConstants.latt_hasnochildrenRemoved',
		'RemovedConstants.latt_markedRemoved',
		'RemovedConstants.latt_noinferiorsRemoved',
		'RemovedConstants.latt_noselectRemoved',
		'RemovedConstants.latt_referralRemoved',
		'RemovedConstants.latt_unmarkedRemoved',
		'RemovedConstants.nilDeprecatedRemoved',
		'RemovedConstants.oci_assocRemoved',
		'RemovedConstants.oci_b_bfileRemoved',
		'RemovedConstants.oci_b_binRemoved',
		'RemovedConstants.oci_b_blobRemoved',
		'RemovedConstants.oci_b_bolRemoved',
		'RemovedConstants.oci_b_cfileeRemoved',
		'RemovedConstants.oci_b_clobRemoved',
		'RemovedConstants.oci_b_cursorRemoved',
		'RemovedConstants.oci_b_intRemoved',
		'RemovedConstants.oci_b_ntyRemoved',
		'RemovedConstants.oci_b_numRemoved',
		'RemovedConstants.oci_b_rowidRemoved',
		'RemovedConstants.oci_bothRemoved',
		'RemovedConstants.oci_commit_on_successRemoved',
		'RemovedConstants.oci_cred_extRemoved',
		'RemovedConstants.oci_d_fileRemoved',
		'RemovedConstants.oci_d_lobRemoved',
		'RemovedConstants.oci_d_rowidRemoved',
		'RemovedConstants.oci_defaultRemoved',
		'RemovedConstants.oci_describe_onlyRemoved',
		'RemovedConstants.oci_dtype_fileRemoved',
		'RemovedConstants.oci_dtype_lobRemoved',
		'RemovedConstants.oci_dtype_rowidRemoved',
		'RemovedConstants.oci_exact_fetchRemoved',
		'RemovedConstants.oci_fetchstatement_by_columnRemoved',
		'RemovedConstants.oci_fetchstatement_by_rowRemoved',
		'RemovedConstants.oci_fo_abortRemoved',
		'RemovedConstants.oci_fo_beginRemoved',
		'RemovedConstants.oci_fo_endRemoved',
		'RemovedConstants.oci_fo_errorRemoved',
		'RemovedConstants.oci_fo_noneRemoved',
		'RemovedConstants.oci_fo_reauthRemoved',
		'RemovedConstants.oci_fo_retryRemoved',
		'RemovedConstants.oci_fo_selectRemoved',
		'RemovedConstants.oci_fo_sessionRemoved',
		'RemovedConstants.oci_fo_txnalRemoved',
		'RemovedConstants.oci_lob_buffer_freeRemoved',
		'RemovedConstants.oci_no_auto_commitRemoved',
		'RemovedConstants.oci_numRemoved',
		'RemovedConstants.oci_return_lobsRemoved',
		'RemovedConstants.oci_return_nullsRemoved',
		'RemovedConstants.oci_seek_curRemoved',
		'RemovedConstants.oci_seek_endRemoved',
		'RemovedConstants.oci_seek_setRemoved',
		'RemovedConstants.oci_sysdateRemoved',
		'RemovedConstants.oci_sysdbaRemoved',
		'RemovedConstants.oci_sysoperRemoved',
		'RemovedConstants.oci_temp_blobRemoved',
		'RemovedConstants.oci_temp_clobRemoved',
		'RemovedConstants.op_anonymousRemoved',
		'RemovedConstants.op_debugRemoved',
		'RemovedConstants.op_expungeRemoved',
		'RemovedConstants.op_halfopenRemoved',
		'RemovedConstants.op_prototypeRemoved',
		'RemovedConstants.op_readonlyRemoved',
		'RemovedConstants.op_secureRemoved',
		'RemovedConstants.op_shortcacheRemoved',
		'RemovedConstants.op_silentRemoved',
		'RemovedConstants.pspell_bad_spellersRemoved',
		'RemovedConstants.pspell_fastRemoved',
		'RemovedConstants.pspell_normalRemoved',
		'RemovedConstants.pspell_run_togetherRemoved',
		'RemovedConstants.sa_allRemoved',
		'RemovedConstants.sa_messagesRemoved',
		'RemovedConstants.sa_recentRemoved',
		'RemovedConstants.sa_uidnextRemoved',
		'RemovedConstants.sa_uidvalidityRemoved',
		'RemovedConstants.sa_unseenRemoved',
		'RemovedConstants.se_freeRemoved',
		'RemovedConstants.se_noprefetchRemoved',
		'RemovedConstants.se_uidRemoved',
		'RemovedConstants.so_freeRemoved',
		'RemovedConstants.so_noserverRemoved',
		'RemovedConstants.sortarrivalRemoved',
		'RemovedConstants.sortccRemoved',
		'RemovedConstants.sortdateRemoved',
		'RemovedConstants.sortfromRemoved',
		'RemovedConstants.sortsizeRemoved',
		'RemovedConstants.sortsubjectRemoved',
		'RemovedConstants.sorttoRemoved',
		'RemovedConstants.sqlt_afcRemoved',
		'RemovedConstants.sqlt_avcRemoved',
		'RemovedConstants.sqlt_bdoubleRemoved',
		'RemovedConstants.sqlt_bfileeRemoved',
		'RemovedConstants.sqlt_bfloatRemoved',
		'RemovedConstants.sqlt_binRemoved',
		'RemovedConstants.sqlt_blobRemoved',
		'RemovedConstants.sqlt_bolRemoved',
		'RemovedConstants.sqlt_cfileeRemoved',
		'RemovedConstants.sqlt_chrRemoved',
		'RemovedConstants.sqlt_clobRemoved',
		'RemovedConstants.sqlt_fltRemoved',
		'RemovedConstants.sqlt_intRemoved',
		'RemovedConstants.sqlt_lbiRemoved',
		'RemovedConstants.sqlt_lngRemoved',
		'RemovedConstants.sqlt_lvcRemoved',
		'RemovedConstants.sqlt_ntyRemoved',
		'RemovedConstants.sqlt_numRemoved',
		'RemovedConstants.sqlt_odtRemoved',
		'RemovedConstants.sqlt_rddRemoved',
		'RemovedConstants.sqlt_rsetRemoved',
		'RemovedConstants.sqlt_strRemoved',
		'RemovedConstants.sqlt_uinRemoved',
		'RemovedConstants.sqlt_vcsRemoved',
		'RemovedConstants.st_setRemoved',
		'RemovedConstants.st_silentRemoved',
		'RemovedConstants.st_uidRemoved',
		'RemovedConstants.typeapplicationRemoved',
		'RemovedConstants.typeaudioRemoved',
		'RemovedConstants.typeimageRemoved',
		'RemovedConstants.typemessageRemoved',
		'RemovedConstants.typemodelRemoved',
		'RemovedConstants.typemultipartRemoved',
		'RemovedConstants.typeotherRemoved',
		'RemovedConstants.typetextRemoved',
		'RemovedConstants.typevideoRemoved',
		'RemovedFunctions.imap_8bitRemoved',
		'RemovedFunctions.imap_alertsRemoved',
		'RemovedFunctions.imap_appendRemoved',
		'RemovedFunctions.imap_base64Removed',
		'RemovedFunctions.imap_binaryRemoved',
		'RemovedFunctions.imap_bodyRemoved',
		'RemovedFunctions.imap_bodystructRemoved',
		'RemovedFunctions.imap_checkRemoved',
		'RemovedFunctions.imap_clearflag_fullRemoved',
		'RemovedFunctions.imap_closeRemoved',
		'RemovedFunctions.imap_createRemoved',
		'RemovedFunctions.imap_createmailboxRemoved',
		'RemovedFunctions.imap_deleteRemoved',
		'RemovedFunctions.imap_deletemailboxRemoved',
		'RemovedFunctions.imap_errorsRemoved',
		'RemovedFunctions.imap_expungeRemoved',
		'RemovedFunctions.imap_fetch_overviewRemoved',
		'RemovedFunctions.imap_fetchbodyRemoved',
		'RemovedFunctions.imap_fetchheaderRemoved',
		'RemovedFunctions.imap_fetchmimeRemoved',
		'RemovedFunctions.imap_fetchstructureRemoved',
		'RemovedFunctions.imap_fetchtextRemoved',
		'RemovedFunctions.imap_gcRemoved',
		'RemovedFunctions.imap_get_quotaRemoved',
		'RemovedFunctions.imap_get_quotarootRemoved',
		'RemovedFunctions.imap_getaclRemoved',
		'RemovedFunctions.imap_getmailboxesRemoved',
		'RemovedFunctions.imap_getsubscribedRemoved',
		'RemovedFunctions.imap_headerinfoRemoved',
		'RemovedFunctions.imap_headersRemoved',
		'RemovedFunctions.imap_is_openRemoved',
		'RemovedFunctions.imap_last_errorRemoved',
		'RemovedFunctions.imap_listRemoved',
		'RemovedFunctions.imap_listmailboxRemoved',
		'RemovedFunctions.imap_listscanRemoved',
		'RemovedFunctions.imap_listsubscribedRemoved',
		'RemovedFunctions.imap_lsubRemoved',
		'RemovedFunctions.imap_mailRemoved',
		'RemovedFunctions.imap_mail_composeRemoved',
		'RemovedFunctions.imap_mail_copyRemoved',
		'RemovedFunctions.imap_mail_moveRemoved',
		'RemovedFunctions.imap_mailboxmsginfoRemoved',
		'RemovedFunctions.imap_mime_header_decodeRemoved',
		'RemovedFunctions.imap_msgnoRemoved',
		'RemovedFunctions.imap_mutf7_to_utf8Removed',
		'RemovedFunctions.imap_num_msgRemoved',
		'RemovedFunctions.imap_num_recentRemoved',
		'RemovedFunctions.imap_openRemoved',
		'RemovedFunctions.imap_pingRemoved',
		'RemovedFunctions.imap_qprintRemoved',
		'RemovedFunctions.imap_renameRemoved',
		'RemovedFunctions.imap_renamemailboxRemoved',
		'RemovedFunctions.imap_reopenRemoved',
		'RemovedFunctions.imap_rfc822_parse_adrlistRemoved',
		'RemovedFunctions.imap_rfc822_parse_headersRemoved',
		'RemovedFunctions.imap_rfc822_write_addressRemoved',
		'RemovedFunctions.imap_savebodyRemoved',
		'RemovedFunctions.imap_scanRemoved',
		'RemovedFunctions.imap_scanmailboxRemoved',
		'RemovedFunctions.imap_searchRemoved',
		'RemovedFunctions.imap_set_quotaRemoved',
		'RemovedFunctions.imap_setaclRemoved',
		'RemovedFunctions.imap_setflag_fullRemoved',
		'RemovedFunctions.imap_sortRemoved',
		'RemovedFunctions.imap_statusRemoved',
		'RemovedFunctions.imap_subscribeRemoved',
		'RemovedFunctions.imap_threadRemoved',
		'RemovedFunctions.imap_timeoutRemoved',
		'RemovedFunctions.imap_uidRemoved',
		'RemovedFunctions.imap_undeleteRemoved',
		'RemovedFunctions.imap_unsubscribeRemoved',
		'RemovedFunctions.imap_utf7_decodeRemoved',
		'RemovedFunctions.imap_utf7_encodeRemoved',
		'RemovedFunctions.imap_utf8Removed',
		'RemovedFunctions.imap_utf8_to_mutf7Removed',
		'RemovedFunctions.oci_bind_array_by_nameRemoved',
		'RemovedFunctions.oci_bind_by_nameRemoved',
		'RemovedFunctions.oci_cancelRemoved',
		'RemovedFunctions.oci_client_versionRemoved',
		'RemovedFunctions.oci_closeRemoved',
		'RemovedFunctions.oci_commitRemoved',
		'RemovedFunctions.oci_connectRemoved',
		'RemovedFunctions.oci_define_by_nameRemoved',
		'RemovedFunctions.oci_errorRemoved',
		'RemovedFunctions.oci_executeRemoved',
		'RemovedFunctions.oci_fetchRemoved',
		'RemovedFunctions.oci_fetch_allRemoved',
		'RemovedFunctions.oci_fetch_arrayRemoved',
		'RemovedFunctions.oci_fetch_assocRemoved',
		'RemovedFunctions.oci_fetch_objectRemoved',
		'RemovedFunctions.oci_fetch_rowRemoved',
		'RemovedFunctions.oci_field_is_nullRemoved',
		'RemovedFunctions.oci_field_nameRemoved',
		'RemovedFunctions.oci_field_precisionRemoved',
		'RemovedFunctions.oci_field_scaleRemoved',
		'RemovedFunctions.oci_field_sizeRemoved',
		'RemovedFunctions.oci_field_typeRemoved',
		'RemovedFunctions.oci_field_type_rawRemoved',
		'RemovedFunctions.oci_free_descriptorRemoved',
		'RemovedFunctions.oci_free_statementRemoved',
		'RemovedFunctions.oci_get_implicit_resultsetRemoved',
		'RemovedFunctions.oci_lob_copyRemoved',
		'RemovedFunctions.oci_lob_is_equalRemoved',
		'RemovedFunctions.oci_new_collectionRemoved',
		'RemovedFunctions.oci_new_connectRemoved',
		'RemovedFunctions.oci_new_cursorRemoved',
		'RemovedFunctions.oci_new_descriptorRemoved',
		'RemovedFunctions.oci_num_fieldsRemoved',
		'RemovedFunctions.oci_num_rowsRemoved',
		'RemovedFunctions.oci_parseRemoved',
		'RemovedFunctions.oci_password_changeRemoved',
		'RemovedFunctions.oci_pconnectRemoved',
		'RemovedFunctions.oci_register_taf_callbackRemoved',
		'RemovedFunctions.oci_resultRemoved',
		'RemovedFunctions.oci_rollbackRemoved',
		'RemovedFunctions.oci_server_versionRemoved',
		'RemovedFunctions.oci_set_actionRemoved',
		'RemovedFunctions.oci_set_call_timeoutRemoved',
		'RemovedFunctions.oci_set_client_identifierRemoved',
		'RemovedFunctions.oci_set_client_infoRemoved',
		'RemovedFunctions.oci_set_db_operationRemoved',
		'RemovedFunctions.oci_set_editionRemoved',
		'RemovedFunctions.oci_set_module_nameRemoved',
		'RemovedFunctions.oci_set_prefetchRemoved',
		'RemovedFunctions.oci_set_prefetch_lobRemoved',
		'RemovedFunctions.oci_statement_typeRemoved',
		'RemovedFunctions.oci_unregister_taf_callbackRemoved',
		'RemovedFunctions.ocibindbynameDeprecatedRemoved',
		'RemovedFunctions.ocicancelDeprecatedRemoved',
		'RemovedFunctions.ocicloselobDeprecatedRemoved',
		'RemovedFunctions.ocicollappendDeprecatedRemoved',
		'RemovedFunctions.ocicollassignDeprecatedRemoved',
		'RemovedFunctions.ocicollassignelemDeprecatedRemoved',
		'RemovedFunctions.ocicollgetelemDeprecatedRemoved',
		'RemovedFunctions.ocicollmaxDeprecatedRemoved',
		'RemovedFunctions.ocicollsizeDeprecatedRemoved',
		'RemovedFunctions.ocicolltrimDeprecatedRemoved',
		'RemovedFunctions.ocicolumnisnullDeprecatedRemoved',
		'RemovedFunctions.ocicolumnnameDeprecatedRemoved',
		'RemovedFunctions.ocicolumnprecisionDeprecatedRemoved',
		'RemovedFunctions.ocicolumnscaleDeprecatedRemoved',
		'RemovedFunctions.ocicolumnsizeDeprecatedRemoved',
		'RemovedFunctions.ocicolumntypeDeprecatedRemoved',
		'RemovedFunctions.ocicolumntyperawDeprecatedRemoved',
		'RemovedFunctions.ocicommitDeprecatedRemoved',
		'RemovedFunctions.ocidefinebynameDeprecatedRemoved',
		'RemovedFunctions.ocierrorDeprecatedRemoved',
		'RemovedFunctions.ociexecuteDeprecatedRemoved',
		'RemovedFunctions.ocifetchDeprecatedRemoved',
		'RemovedFunctions.ocifetchintoDeprecatedRemoved',
		'RemovedFunctions.ocifetchstatementDeprecatedRemoved',
		'RemovedFunctions.ocifreecollectionDeprecatedRemoved',
		'RemovedFunctions.ocifreecursorDeprecatedRemoved',
		'RemovedFunctions.ocifreedescDeprecatedRemoved',
		'RemovedFunctions.ocifreestatementDeprecatedRemoved',
		'RemovedFunctions.ociloadlobDeprecatedRemoved',
		'RemovedFunctions.ocilogoffDeprecatedRemoved',
		'RemovedFunctions.ocilogonDeprecatedRemoved',
		'RemovedFunctions.ocinewcollectionDeprecatedRemoved',
		'RemovedFunctions.ocinewcursorDeprecatedRemoved',
		'RemovedFunctions.ocinewdescriptorDeprecatedRemoved',
		'RemovedFunctions.ocinlogonDeprecatedRemoved',
		'RemovedFunctions.ocinumcolsDeprecatedRemoved',
		'RemovedFunctions.ociparseDeprecatedRemoved',
		'RemovedFunctions.ociplogonDeprecatedRemoved',
		'RemovedFunctions.ociresultDeprecatedRemoved',
		'RemovedFunctions.ocirollbackDeprecatedRemoved',
		'RemovedFunctions.ocirowcountDeprecatedRemoved',
		'RemovedFunctions.ocisavelobDeprecatedRemoved',
		'RemovedFunctions.ocisavelobfileDeprecatedRemoved',
		'RemovedFunctions.ociserverversionDeprecatedRemoved',
		'RemovedFunctions.ocisetprefetchDeprecatedRemoved',
		'RemovedFunctions.ocistatementtypeDeprecatedRemoved',
		'RemovedFunctions.ociwritelobtofileDeprecatedRemoved',
		'RemovedFunctions.ociwritetemporarylobDeprecatedRemoved',
		'RemovedFunctions.pspell_add_to_personalRemoved',
		'RemovedFunctions.pspell_add_to_sessionRemoved',
		'RemovedFunctions.pspell_checkRemoved',
		'RemovedFunctions.pspell_clear_sessionRemoved',
		'RemovedFunctions.pspell_config_createRemoved',
		'RemovedFunctions.pspell_config_data_dirRemoved',
		'RemovedFunctions.pspell_config_dict_dirRemoved',
		'RemovedFunctions.pspell_config_ignoreRemoved',
		'RemovedFunctions.pspell_config_modeRemoved',
		'RemovedFunctions.pspell_config_personalRemoved',
		'RemovedFunctions.pspell_config_replRemoved',
		'RemovedFunctions.pspell_config_runtogetherRemoved',
		'RemovedFunctions.pspell_config_save_replRemoved',
		'RemovedFunctions.pspell_newRemoved',
		'RemovedFunctions.pspell_new_configRemoved',
		'RemovedFunctions.pspell_new_personalRemoved',
		'RemovedFunctions.pspell_save_wordlistRemoved',
		'RemovedFunctions.pspell_store_replacementRemoved',
		'RemovedFunctions.pspell_suggestRemoved',
		'RemovedFunctions.xmlrpc_decodeRemoved',
		'RemovedFunctions.xmlrpc_decode_requestRemoved',
		'RemovedFunctions.xmlrpc_encodeRemoved',
		'RemovedFunctions.xmlrpc_encode_requestRemoved',
		'RemovedFunctions.xmlrpc_get_typeRemoved',
		'RemovedFunctions.xmlrpc_is_faultRemoved',
		'RemovedFunctions.xmlrpc_parse_method_descriptionsRemoved',
		'RemovedFunctions.xmlrpc_server_add_introspection_dataRemoved',
		'RemovedFunctions.xmlrpc_server_call_methodRemoved',
		'RemovedFunctions.xmlrpc_server_createRemoved',
		'RemovedFunctions.xmlrpc_server_destroyRemoved',
		'RemovedFunctions.xmlrpc_server_register_introspection_callbackRemoved',
		'RemovedFunctions.xmlrpc_server_register_methodRemoved',
		'RemovedFunctions.xmlrpc_set_typeRemoved',
	);

	/**
	 * NEEDS_EXTENSION_CODES as a lookup, built on first use.
	 *
	 * @var array|null
	 */
	private static $needs_extension = null;

	/**
	 * All categories, in display order.
	 *
	 * @return string[]
	 */
	public static function all() {
		return array( self::REMOVED, self::NEEDS_EXTENSION, self::DEPRECATED, self::CHANGED, self::NEWER_THAN_CURRENT, self::ADVISORY, self::IGNORED );
	}

	/**
	 * Categories that count as upgrade problems (M4, problem totals). newer_than_current, advisory and ignored are
	 * information only (spec §2). A finding of these categories also counts only when it is new for the upgrade
	 * (not reported for the "from" version alone, DECISIONS D52). needs_extension counts, but never as "will break".
	 *
	 * @return string[]
	 */
	public static function counted() {
		return array( self::REMOVED, self::NEEDS_EXTENSION, self::DEPRECATED, self::CHANGED );
	}

	/**
	 * Whether "Sniff.code" belongs to an extension PHP moved out of core (DECISIONS D55).
	 *
	 * @param string $tail Last two segments of the rule code.
	 * @return bool
	 */
	private static function is_needs_extension( $tail ) {
		if ( null === self::$needs_extension ) {
			self::$needs_extension = array_flip( self::NEEDS_EXTENSION_CODES );
		}
		return isset( self::$needs_extension[ $tail ] );
	}

	/**
	 * Whether a rule code is PHPCS saying it could not fully process the file: Internal.Exception,
	 * Internal.Tokenizer.Exception (any Internal.* code ending in "Exception"; DECISIONS D52).
	 *
	 * @param string $rule PHPCS rule code.
	 * @return bool
	 */
	public static function is_aborted( $rule ) {
		$rule = (string) $rule;
		return 0 === strpos( $rule, 'Internal.' ) && 'Exception' === substr( $rule, -9 );
	}

	/**
	 * Category of a rule code, e.g. PHPCompatibility.FunctionUse.RemovedFunctions.eachDeprecatedRemoved → removed.
	 *
	 * PHPCS's Internal.LineEndings.* (mixed line endings) is not a PHP version issue → ignored (DECISIONS D52).
	 * Internal.Exception / Internal.Tokenizer.Exception (the file was not fully checked, is_aborted()) never goes there: the runner reports it as a file status
	 * (Runner::split_internal()). Then the last two segments (sniff name + error code): the
	 * explicit lists first (advisory, changed sniffs, deprecated codes; D37; *SoftRequired and extensions moved out of
	 * core, D55); then New* sniff; then the error code
	 * (ends in Removed, or contains Deprecated), because PHPCompatibility names its sniffs Removed* even for
	 * deprecation-only codes such as RemovedFunctions.utf8_encodeDeprecated (D33); then Removed / Deprecated
	 * anywhere; else changed.
	 *
	 * @param string $rule PHPCS rule code.
	 * @return string
	 */
	public static function of( $rule ) {
		$rule = (string) $rule;
		if ( 0 === strpos( $rule, 'Upshift.Php82.DynamicProperty' ) ) {
			return self::DEPRECATED;
		}
		if ( 0 === strpos( $rule, 'Internal.LineEndings.' ) ) {
			return self::IGNORED;
		}
		if ( self::is_aborted( $rule ) ) {
			// The runner turns it into the file status "partial" (D52); only rows stored before T7.5.1 have it.
			return self::ADVISORY;
		}
		$parts = array_slice( explode( '.', $rule ), -2 );
		$sniff = count( $parts ) > 1 ? $parts[0] : '';
		$code  = (string) end( $parts );
		$tail  = implode( '.', $parts );
		if ( in_array( $tail, self::ADVISORY_CODES, true ) ) {
			return self::ADVISORY;
		}
		if ( in_array( $sniff, self::CHANGED_SNIFFS, true ) ) {
			return self::CHANGED;
		}
		if ( in_array( $tail, self::DEPRECATED_CODES, true ) ) {
			return self::DEPRECATED;
		}
		// A parameter that stops being optional: "Soft" = only deprecated so far (DECISIONS D55).
		if ( 'SoftRequired' === substr( $code, -12 ) ) {
			return self::DEPRECATED;
		}
		if ( self::is_needs_extension( $tail ) ) {
			return self::NEEDS_EXTENSION;
		}
		if ( 0 === strpos( $sniff, 'New' ) ) {
			return self::NEWER_THAN_CURRENT;
		}
		if ( 'Removed' === substr( $code, -7 ) ) {
			return self::REMOVED;
		}
		if ( false !== strpos( $code, 'Deprecated' ) ) {
			return self::DEPRECATED;
		}
		if ( false !== strpos( $tail, 'Removed' ) ) {
			return self::REMOVED;
		}
		if ( false !== strpos( $tail, 'Deprecated' ) ) {
			return self::DEPRECATED;
		}
		return self::CHANGED;
	}
}
