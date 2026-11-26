/**
 * Block "Answely answer field" (answely/ask) in the block editor.
 *
 * The block is rendered on the server (render callback in answely.php); the
 * editor only shows a preview and the three text settings. The translated
 * texts come from window.answelyBlockTexts.
 */
( function ( wp, t ) {
	'use strict';
	if ( ! wp || ! t ) {
		return;
	}

	var el = wp.element.createElement;
	var be = wp.blockEditor;
	var C = wp.components;

	wp.blocks.registerBlockType( 'answely/ask', {
		apiVersion: 3,
		title: t.title,
		description: t.description,
		icon: 'search',
		category: 'widgets',
		keywords: [ 'answely', 'ai', 'search', 'answer' ],
		supports: { html: false },
		attributes: {
			label: { type: 'string', default: '' },
			placeholder: { type: 'string', default: '' },
			button: { type: 'string', default: '' },
		},

		edit: function ( p ) {
			var a = p.attributes;
			var set = function ( k ) {
				return function ( v ) {
					var o = {};
					o[ k ] = v;
					p.setAttributes( o );
				};
			};
			var bp = be.useBlockProps ? be.useBlockProps( { className: 'answely-ask' } ) : { className: 'answely-ask' };

			return el(
				'div',
				bp,
				el(
					be.InspectorControls,
					null,
					el(
						C.PanelBody,
						{ title: t.settings },
						el( C.TextControl, { label: t.label, value: a.label, placeholder: t.defLabel, onChange: set( 'label' ) } ),
						el( C.TextControl, { label: t.placeholder, value: a.placeholder, placeholder: t.defPh, onChange: set( 'placeholder' ) } ),
						el( C.TextControl, { label: t.button, value: a.button, placeholder: t.defButton, onChange: set( 'button' ) } )
					)
				),
				el( 'label', { className: 'answely-ask__label' }, a.label || t.defLabel ),
				el(
					'div',
					{ className: 'answely-ask__row' },
					el( 'input', { className: 'answely-ask__input', type: 'text', readOnly: true, placeholder: a.placeholder || t.defPh } ),
					el( 'button', { className: 'answely-ask__button wp-element-button', type: 'button' }, a.button || t.defButton )
				),
				el( 'p', { style: { fontSize: '12px', opacity: 0.7 } }, t.note )
			);
		},

		save: function () {
			return null;
		},
	} );
} )( window.wp, window.answelyBlockTexts );
