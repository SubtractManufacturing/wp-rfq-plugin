(function () {
    "use strict";

    var config = window.rfqCatalogEditor;

    if ( ! config) {
        return;
    }

    var root        = document.getElementById( "rfq-catalog-editor-root" );
    var tbody       = document.getElementById( "rfq-catalog-rows" );
    var jsonField   = document.getElementById( "rfq_material_overrides" );
    var previewList = document.getElementById( "rfq-effective-preview-list" );
    var jsonError   = document.querySelector( ".rfq-catalog-json-error" );
    var addButton   = document.getElementById( "rfq-catalog-add-row" );
    var form        = document.getElementById( "rfq-catalog-editor-form" );

    if ( ! root || ! tbody || ! jsonField || ! previewList) {
        return;
    }

    var syncing      = false;
    var jsonDebounce = null;

    function defaultLabelForId(id) {
        for (var i = 0; i < config.defaults.length; i += 1) {
            if (config.defaults[i].id === id) {
                return config.defaults[i].label;
            }
        }

        return "";
    }

    function defaultEntryForId(id) {
        for (var i = 0; i < config.defaults.length; i += 1) {
            if (config.defaults[i].id === id) {
                return config.defaults[i];
            }
        }

        return null;
    }

    function isValidMaterialId(id) {
        return /^[a-z0-9]+(?:-[a-z0-9]+)*$/.test( id );
    }

    function isValidOverrideShape(overrides) {
        if ( ! overrides || typeof overrides !== "object" || Array.isArray( overrides )) {
            return false;
        }

        var keys = ["disabled", "disable", "renamed", "rename", "added", "add"];

        for (var i = 0; i < keys.length; i += 1) {
            var key = keys[i];

            if ( ! (key in overrides)) {
                continue;
            }

            var value = overrides[key];

            if (key === "disabled" || key === "disable") {
                if ( ! Array.isArray( value )) {
                    return false;
                }

                for (var d = 0; d < value.length; d += 1) {
                    if (typeof value[d] !== "string" || value[d] === "") {
                        return false;
                    }
                }
            }

            if (key === "renamed" || key === "rename") {
                if ( ! value || typeof value !== "object" || Array.isArray( value )) {
                    return false;
                }

                for (var renamedId in value) {
                    if ( ! Object.prototype.hasOwnProperty.call( value, renamedId )) {
                        continue;
                    }

                    if (
                        typeof renamedId !== "string" ||
                        renamedId === "" ||
                        typeof value[renamedId] !== "string" ||
                        value[renamedId] === ""
                    ) {
                        return false;
                    }
                }
            }

            if (key === "added" || key === "add") {
                if ( ! Array.isArray( value )) {
                    return false;
                }

                for (var a = 0; a < value.length; a += 1) {
                    var entry = value[a];

                    if ( ! entry || typeof entry !== "object" || Array.isArray( entry )) {
                        return false;
                    }

                    if (typeof entry.id !== "string" || entry.id === "" || typeof entry.label !== "string" || entry.label === "") {
                        return false;
                    }
                }
            }
        }

        return true;
    }

    function parseAliases(value) {
        if ( ! value) {
            return [];
        }

        return value
            .split( "," )
            .map(
                function (alias) {
					return alias.trim();
				}
            )
            .filter(
                function (alias) {
					return alias !== "";
				}
            );
    }

    function collectRowsFromDom() {
        var rows     = [];
        var elements = tbody.querySelectorAll( ".rfq-catalog-row" );

        elements.forEach(
            function (rowEl) {
				var source        = rowEl.getAttribute( "data-source" ) || "shipped";
				var idInput       = rowEl.querySelector( ".rfq-row-id" );
				var labelInput    = rowEl.querySelector( ".rfq-row-label" );
				var enabledInput  = rowEl.querySelector( ".rfq-row-enabled" );
				var aliasesInput  = rowEl.querySelector( ".rfq-row-aliases" );
				var dropdownInput = rowEl.querySelector( ".rfq-row-dropdown" );
				var id            = idInput ? idInput.value.trim() : "";
				var label         = labelInput ? labelInput.value.trim() : "";
				var defaultLabel  = labelInput ? labelInput.getAttribute( "data-default-label" ) || label : label;

				rows.push(
                    {
						id: id,
						label: label,
						default_label: defaultLabel,
						aliases: aliasesInput ? parseAliases( aliasesInput.value ) : [],
						show_in_dropdown: dropdownInput ? dropdownInput.checked : false,
						enabled: enabledInput ? enabledInput.checked : true,
						source: source,
                    }
				);
			}
        );

        return rows;
    }

    function buildOverridesFromRows(rows) {
        var disabled = [];
        var renamed  = {};
        var added    = [];
        var seen     = {};

        for (var i = 0; i < rows.length; i += 1) {
            var row   = rows[i];
            var id    = row.id;
            var label = row.label;

            if ( ! id || ! label) {
                return null;
            }

            if (seen[id]) {
                return null;
            }

            seen[id] = true;

            if (row.source === "custom") {
                if ( ! isValidMaterialId( id )) {
                    return null;
                }

                if ( ! row.enabled) {
                    disabled.push( id );
                }

                added.push(
                    {
						id: id,
						label: label,
						aliases: row.aliases || [],
						show_in_dropdown: ! ! row.show_in_dropdown,
                    }
                );

                continue;
            }

            if ( ! row.enabled) {
                disabled.push( id );
            }

            if (label !== row.default_label) {
                renamed[id] = label;
            }
        }

        var overrides = {};

        if (disabled.length > 0) {
            overrides.disabled = disabled;
        }

        if (Object.keys( renamed ).length > 0) {
            overrides.renamed = renamed;
        }

        if (added.length > 0) {
            overrides.added = added;
        }

        return overrides;
    }

    function encodeOverrides(overrides) {
        if ( ! overrides || Object.keys( overrides ).length === 0) {
            return "";
        }

        return JSON.stringify( overrides, null, 2 );
    }

    function normalizeOverrides(overrides) {
        var disabled = [];

        if (Array.isArray( overrides.disabled )) {
            disabled = disabled.concat( overrides.disabled );
        }

        if (Array.isArray( overrides.disable )) {
            disabled = disabled.concat( overrides.disable );
        }

        var renamed = {};

        if (overrides.renamed && typeof overrides.renamed === "object") {
            renamed = Object.assign( {}, overrides.renamed );
        }

        if (overrides.rename && typeof overrides.rename === "object") {
            renamed = Object.assign( renamed, overrides.rename );
        }

        var added = [];

        if (Array.isArray( overrides.added )) {
            added = added.concat( overrides.added );
        }

        if (Array.isArray( overrides.add )) {
            added = added.concat( overrides.add );
        }

        return {
            disabled: disabled,
            renamed: renamed,
            added: added,
        };
    }

    function buildEditorRowsFromOverrides(overrides) {
        var normalized = normalizeOverrides( overrides || {} );
        var disabled   = {};

        normalized.disabled.forEach(
            function (id) {
				disabled[id] = true;
			}
        );

        var rows = [];

        config.defaults.forEach(
            function (entry) {
				rows.push(
                    {
						id: entry.id,
						label: normalized.renamed[entry.id] || entry.label,
						default_label: entry.label,
						aliases: entry.aliases || [],
						show_in_dropdown: ! ! entry.show_in_dropdown,
						enabled: ! disabled[entry.id],
						source: "shipped",
						can_edit_aliases: false,
						can_edit_dropdown: false,
						can_remove: false,
                    }
				);
			}
        );

        var defaultIds = config.defaults.map(
            function (entry) {
				return entry.id;
			}
        );

        normalized.added.forEach(
            function (entry) {
				if ( ! entry || typeof entry.id !== "string") {
					return;
				}

				if (defaultIds.indexOf( entry.id ) !== -1) {
					return;
				}

				rows.push(
                    {
						id: entry.id,
						label: entry.label,
						default_label: entry.label,
						aliases: Array.isArray( entry.aliases ) ? entry.aliases : [],
						show_in_dropdown: ! ! entry.show_in_dropdown,
						enabled: ! disabled[entry.id],
						source: "custom",
						can_edit_aliases: true,
						can_edit_dropdown: true,
						can_remove: true,
                    }
				);
			}
        );

        return rows;
    }

    function buildEffectivePreview(rows) {
        return rows
            .filter(
                function (row) {
					return row.enabled;
				}
            )
            .map(
                function (row) {
					return row.label;
				}
            );
    }

    function setJsonError(message) {
        if ( ! jsonError) {
            return;
        }

        var messageEl = jsonError.querySelector( "span" );

        if (message) {
            jsonError.classList.remove( "hidden" );

            if (messageEl) {
                messageEl.textContent = message;
            }
        } else {
            jsonError.classList.add( "hidden" );

            if (messageEl) {
                messageEl.textContent = "";
            }
        }
    }

    function renderPreview(rows) {
        var labels = buildEffectivePreview( rows );

        previewList.innerHTML = "";

        labels.forEach(
            function (label) {
				var item         = document.createElement( "li" );
				item.textContent = label;
				previewList.appendChild( item );
			}
        );
    }

    function renderRow(row, index) {
        var tr       = document.createElement( "tr" );
        tr.className = "rfq-catalog-row" + (row.enabled ? "" : " rfq-catalog-row-disabled");
        tr.setAttribute( "data-row-index", String( index ) );
        tr.setAttribute( "data-source", row.source );

        var enabledCell       = document.createElement( "td" );
        enabledCell.className = "rfq-col-enabled";
        enabledCell.innerHTML =
            '<input type="checkbox" class="rfq-row-enabled" id="rfq-row-' +
            index +
            '-enabled"' +
            (row.enabled ? " checked" : "") +
            " />";
        tr.appendChild( enabledCell );

        var labelCell       = document.createElement( "td" );
        labelCell.className = "rfq-col-label";
        labelCell.innerHTML =
            '<input type="text" class="regular-text rfq-row-label" id="rfq-row-' +
            index +
            '-label" value="' +
            escapeAttribute( row.label ) +
            '" data-default-label="' +
            escapeAttribute( row.default_label ) +
            '" />';
        tr.appendChild( labelCell );

        var idCell       = document.createElement( "td" );
        idCell.className = "rfq-col-id";

        if (row.source === "custom") {
            idCell.innerHTML =
                '<input type="text" class="regular-text rfq-row-id" id="rfq-row-' +
                index +
                '-id" value="' +
                escapeAttribute( row.id ) +
                '" />';
        } else {
            idCell.innerHTML =
                '<code class="rfq-row-id-display">' +
                escapeHtml( row.id ) +
                '</code><input type="hidden" class="rfq-row-id" value="' +
                escapeAttribute( row.id ) +
                '" />';
        }

        tr.appendChild( idCell );

        var aliasesCell       = document.createElement( "td" );
        aliasesCell.className = "rfq-col-aliases";

        if (row.can_edit_aliases) {
            aliasesCell.innerHTML =
                '<input type="text" class="regular-text rfq-row-aliases" id="rfq-row-' +
                index +
                '-aliases" value="' +
                escapeAttribute( (row.aliases || []).join( ", " ) ) +
                '" placeholder="Comma-separated" />';
        } else {
            aliasesCell.innerHTML =
                '<span class="rfq-row-aliases-display">' + escapeHtml( (row.aliases || []).join( ", " ) ) + "</span>";
        }

        tr.appendChild( aliasesCell );

        var dropdownCell       = document.createElement( "td" );
        dropdownCell.className = "rfq-col-dropdown";

        if (row.can_edit_dropdown) {
            dropdownCell.innerHTML =
                '<input type="checkbox" class="rfq-row-dropdown" id="rfq-row-' +
                index +
                '-dropdown"' +
                (row.show_in_dropdown ? " checked" : "") +
                " />";
        } else {
            dropdownCell.innerHTML =
                '<span class="rfq-row-dropdown-display">' + (row.show_in_dropdown ? "Yes" : "No") + "</span>";
        }

        tr.appendChild( dropdownCell );

        var sourceCell       = document.createElement( "td" );
        sourceCell.className = "rfq-col-source";
        sourceCell.innerHTML =
            '<span class="rfq-source-badge rfq-source-' +
            row.source +
            '">' +
            (row.source === "custom" ? config.i18n.custom : config.i18n.shipped) +
            "</span>";
        tr.appendChild( sourceCell );

        var actionsCell       = document.createElement( "td" );
        actionsCell.className = "rfq-col-actions";

        if (row.can_remove) {
            actionsCell.innerHTML = '<button type="button" class="button-link-delete rfq-row-remove">' + config.i18n.remove + "</button>";
        } else {
            actionsCell.innerHTML = '<span aria-hidden="true">—</span>';
        }

        tr.appendChild( actionsCell );

        return tr;
    }

    function escapeHtml(value) {
        return String( value )
            .replace( /&/g, "&amp;" )
            .replace( /</g, "&lt;" )
            .replace( />/g, "&gt;" )
            .replace( /"/g, "&quot;" );
    }

    function escapeAttribute(value) {
        return escapeHtml( value ).replace( /'/g, "&#39;" );
    }

    function renderRows(rows) {
        tbody.innerHTML = "";

        rows.forEach(
            function (row, index) {
				tbody.appendChild( renderRow( row, index ) );
			}
        );
    }

    function syncUiToJson() {
        if (syncing) {
            return;
        }

        syncing = true;

        var rows      = collectRowsFromDom();
        var overrides = buildOverridesFromRows( rows );

        if ( ! overrides) {
            setJsonError( config.i18n.duplicateId );
            syncing = false;
            return;
        }

        setJsonError( "" );
        jsonField.value = encodeOverrides( overrides );
        renderPreview( rows );

        rows.forEach(
            function (row, index) {
				var rowEl = tbody.querySelectorAll( ".rfq-catalog-row" )[index];

				if ( ! rowEl) {
					return;
				}

				if (row.enabled) {
					rowEl.classList.remove( "rfq-catalog-row-disabled" );
				} else {
					rowEl.classList.add( "rfq-catalog-row-disabled" );
				}
			}
        );

        syncing = false;
    }

    function syncJsonToUi() {
        if (syncing) {
            return;
        }

        var raw = jsonField.value.trim();

        if (raw === "") {
            setJsonError( "" );
            renderRows( buildEditorRowsFromOverrides( {} ) );
            renderPreview( collectRowsFromDom() );
            return;
        }

        var parsed;

        try {
            parsed = JSON.parse( raw );
        } catch (error) {
            setJsonError( config.i18n.jsonError );
            return;
        }

        if ( ! isValidOverrideShape( parsed )) {
            setJsonError( config.i18n.jsonError );
            return;
        }

        setJsonError( "" );
        syncing = true;
        renderRows( buildEditorRowsFromOverrides( parsed ) );
        renderPreview( collectRowsFromDom() );
        syncing = false;
    }

    function addCustomRow() {
        var rows   = collectRowsFromDom();
        var baseId = "custom-material";
        var suffix = 1;
        var ids    = {};

        rows.forEach(
            function (row) {
				ids[row.id] = true;
			}
        );

        while (ids[baseId + "-" + suffix]) {
            suffix += 1;
        }

        rows.push(
            {
				id: baseId + "-" + suffix,
				label: "New Material",
				default_label: "New Material",
				aliases: [],
				show_in_dropdown: true,
				enabled: true,
				source: "custom",
				can_edit_aliases: true,
				can_edit_dropdown: true,
				can_remove: true,
            }
        );

        renderRows( rows );
        syncUiToJson();
    }

    tbody.addEventListener(
        "input",
        function (event) {
			if ( ! (event.target instanceof HTMLElement)) {
				return;
			}

			if (
            event.target.classList.contains( "rfq-row-label" ) ||
            event.target.classList.contains( "rfq-row-id" ) ||
            event.target.classList.contains( "rfq-row-aliases" )
			) {
				syncUiToJson();
			}
		}
    );

    tbody.addEventListener(
        "change",
        function (event) {
			if ( ! (event.target instanceof HTMLElement)) {
				return;
			}

			if (
            event.target.classList.contains( "rfq-row-enabled" ) ||
            event.target.classList.contains( "rfq-row-dropdown" )
			) {
				syncUiToJson();
			}
		}
    );

    tbody.addEventListener(
        "click",
        function (event) {
			if ( ! (event.target instanceof HTMLElement)) {
				return;
			}

			if ( ! event.target.classList.contains( "rfq-row-remove" )) {
				return;
			}

			var rowEl = event.target.closest( ".rfq-catalog-row" );

			if ( ! rowEl) {
				return;
			}

			rowEl.remove();
			syncUiToJson();
		}
    );

    if (addButton) {
        addButton.addEventListener( "click", addCustomRow );
    }

    jsonField.addEventListener(
        "input",
        function () {
			window.clearTimeout( jsonDebounce );
			jsonDebounce = window.setTimeout( syncJsonToUi, 250 );
		}
    );

    if (form) {
        form.addEventListener(
            "submit",
            function (event) {
				syncUiToJson();

				if (jsonError && ! jsonError.classList.contains( "hidden" )) {
					event.preventDefault();
				}
			}
        );
    }

    renderPreview( collectRowsFromDom() );
})();
