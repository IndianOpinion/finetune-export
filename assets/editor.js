/**
 * finetune-export: "Exclude from training exports" switch in the block
 * editor document panel. Plain script, no build step. Loaded only for users
 * with the plugin's capability.
 */
( function ( wp, config ) {
	'use strict';

	if ( ! wp || ! wp.plugins || ! wp.data || ! config ) {
		return;
	}

	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var Panel =
		( wp.editor && wp.editor.PluginDocumentSettingPanel ) ||
		( wp.editPost && wp.editPost.PluginDocumentSettingPanel );
	if ( ! Panel ) {
		return;
	}

	function OptOutPanel() {
		var meta = wp.data.useSelect( function ( select ) {
			return select( 'core/editor' ).getEditedPostAttribute( 'meta' ) || {};
		}, [] );
		var editPost = wp.data.useDispatch( 'core/editor' ).editPost;
		var checked = !! meta[ config.metaKey ];

		return el(
			Panel,
			{
				name: 'finetune-export-opt-out',
				title: __( 'Training exports', 'finetune-export' ),
			},
			el( wp.components.CheckboxControl, {
				label: __( 'Exclude from training exports', 'finetune-export' ),
				checked: checked,
				onChange: function ( value ) {
					var change = {};
					change[ config.metaKey ] = !! value;
					editPost( { meta: change } );
				},
			} )
		);
	}

	wp.plugins.registerPlugin( 'finetune-export-opt-out', { render: OptOutPanel } );
} )( window.wp, window.finetuneExportOptOut );
