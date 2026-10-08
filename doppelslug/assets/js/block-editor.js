/**
 * Doppelslug for the block editor: a warning notice, a sidebar panel, and a
 * pre-publish check listing published content whose slug starts like this one.
 *
 * Plain script with no build step; it uses the WordPress globals listed as
 * dependencies in class-doppelslug-editor.php.
 */
( function ( wp ) {
	'use strict';

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var useRef = wp.element.useRef;
	var useSelect = wp.data.useSelect;
	var useDispatch = wp.data.useDispatch;
	var __ = wp.i18n.__;
	var sprintf = wp.i18n.sprintf;
	var ExternalLink = wp.components.ExternalLink;
	var PluginDocumentSettingPanel = wp.editor.PluginDocumentSettingPanel;
	var PluginPrePublishPanel = wp.editor.PluginPrePublishPanel;

	var NOTICE_ID = 'doppelslug-lookalikes';
	var PANEL = 'doppelslug/doppelslug-panel';
	var DELAY = 800;

	/**
	 * Opens the post sidebar and expands the "Lookalike URLs" panel, which starts collapsed.
	 */
	function showPanel() {
		var sidebar = wp.data.dispatch( 'core/interface' );
		var editor = wp.data.select( 'core/editor' );

		if ( sidebar && sidebar.enableComplementaryArea ) {
			sidebar.enableComplementaryArea( 'core', 'edit-post/document' );
		}

		if ( editor.isEditorPanelEnabled( PANEL ) && ! editor.isEditorPanelOpened( PANEL ) ) {
			wp.data.dispatch( 'core/editor' ).toggleEditorPanelOpened( PANEL );
		}
	}

	/**
	 * Fetches the report whenever the slug (or, before there is one, the title) settles.
	 *
	 * @return {Object|null} Report from the REST endpoint, { status: 'error' }, or null while loading.
	 */
	function useReport() {
		var post = useSelect( function ( select ) {
			var editor = select( 'core/editor' );

			return {
				id: editor.getCurrentPostId(),
				slug: editor.getEditedPostAttribute( 'slug' ) || '',
				title: editor.getEditedPostAttribute( 'title' ) || '',
				status: editor.getEditedPostAttribute( 'status' ),
			};
		}, [] );

		var state = useState( null );
		var report = state[ 0 ];
		var setReport = state[ 1 ];

		// The title only matters until the post has a slug of its own.
		var source = post.slug ? 'slug:' + post.slug : 'title:' + post.title;

		useEffect(
			function () {
				var cancelled = false;
				var timer;

				if ( ! post.id || ( ! post.slug && ! post.title ) ) {
					setReport( null );
					return undefined;
				}

				timer = setTimeout( function () {
					wp.apiFetch( {
						path: wp.url.addQueryArgs( '/doppelslug/v1/check', {
							post_id: post.id,
							slug: post.slug,
							title: post.slug ? '' : post.title,
						} ),
					} )
						.then( function ( result ) {
							if ( ! cancelled ) {
								setReport( result );
							}
						} )
						.catch( function () {
							if ( ! cancelled ) {
								setReport( { status: 'error' } );
							}
						} );
				}, DELAY );

				return function () {
					cancelled = true;
					clearTimeout( timer );
				};
			},
			[ post.id, source, post.status ]
		);

		return report;
	}

	/**
	 * Shows one warning notice per distinct set of lookalikes, and removes it once they are gone.
	 *
	 * @param {Object|null} report Current report.
	 */
	function useWarningNotice( report ) {
		var notices = useDispatch( 'core/notices' );
		var shown = useRef( '' );

		useEffect(
			function () {
				var key;

				if ( ! report || 'conflicts' !== report.status ) {
					if ( shown.current ) {
						notices.removeNotice( NOTICE_ID );
						shown.current = '';
					}
					return;
				}

				key = report.groups
					.map( function ( group ) {
						return group.address + ':' + group.posts.map( function ( p ) { return p.id; } ).join( ',' );
					} )
					.join( '|' );

				if ( key === shown.current ) {
					return;
				}

				shown.current = key;
				notices.createWarningNotice(
					sprintf(
						/* translators: %s: sentence saying how many published items have a similar address. */
						__( '%s Visitors who type a shortened address could land on the wrong one.', 'doppelslug' ),
						report.summary
					),
					{
						id: NOTICE_ID,
						isDismissible: true,
						actions: [ { label: __( 'Show details', 'doppelslug' ), onClick: showPanel } ],
					}
				);
			},
			[ report ]
		);
	}

	/**
	 * Renders a report as text and links.
	 *
	 * @param {Object} props        Component props.
	 * @param {Object} props.report Report, or null while loading.
	 * @return {Element} Report markup.
	 */
	function ReportView( props ) {
		var report = props.report;
		var children;

		if ( ! report ) {
			return el( 'p', null, __( 'Checking for lookalike addresses…', 'doppelslug' ) );
		}

		if ( 'error' === report.status ) {
			return el( 'p', null, __( 'Could not check for lookalike addresses.', 'doppelslug' ) );
		}

		children = [ el( 'p', { key: 'summary' }, report.summary ) ];

		( report.groups || [] ).forEach( function ( group, index ) {
			children.push(
				el(
					'div',
					{ key: 'group-' + index, className: 'doppelslug-group' },
					el( 'p', null, group.message ),
					el(
						'ul',
						null,
						group.posts.map( function ( item ) {
							return el( 'li', { key: item.id }, el( ExternalLink, { href: item.link }, item.title ) );
						} )
					),
					group.now ? el( 'p', { className: 'doppelslug-note' }, group.now ) : null
				)
			);
		} );

		if ( report.tip ) {
			children.push( el( 'p', { key: 'tip', className: 'doppelslug-note' }, report.tip ) );
		}

		if ( report.settingsUrl && 'conflicts' === report.status ) {
			children.push(
				el(
					'p',
					{ key: 'settings' },
					el( ExternalLink, { href: report.settingsUrl }, __( 'Change how WordPress handles these addresses', 'doppelslug' ) )
				)
			);
		}

		return el( Fragment, null, children );
	}

	/**
	 * Root component: the sidebar panel appears only when there is something to warn about;
	 * the pre-publish check always reports.
	 *
	 * @return {Element|null} Panels.
	 */
	function Doppelslug() {
		var report = useReport();
		var hasConflicts = !! report && 'conflicts' === report.status;

		useWarningNotice( report );

		if ( report && ( 'not_applicable' === report.status || 'guessing_off' === report.status ) ) {
			return null;
		}

		return el(
			Fragment,
			null,
			hasConflicts
				? el(
					PluginDocumentSettingPanel,
					{ name: 'doppelslug-panel', title: __( 'Lookalike URLs', 'doppelslug' ), className: 'doppelslug-panel' },
					el( ReportView, { report: report } )
				)
				: null,
			el(
				PluginPrePublishPanel,
				{ title: __( 'Lookalike URLs', 'doppelslug' ), initialOpen: hasConflicts, className: 'doppelslug-panel' },
				el( ReportView, { report: report } )
			)
		);
	}

	wp.plugins.registerPlugin( 'doppelslug', { render: Doppelslug } );
}( window.wp ) );
