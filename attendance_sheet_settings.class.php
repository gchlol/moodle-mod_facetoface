<?php
/**
 * Class attendance_sheet_settings
 *
 * The Face-to-Face plugin allows a facilitator to see students enrolled in a session
 * and then print out the students' information for them to sign their names.
 * This class sets up and renders the attendance sheet configuration.
 *
 * @package   mod_facetoface
 */

use mod_facetoface\enum\attendance_sheet_column;

defined('MOODLE_INTERNAL') || die();

// Include the enum definitions.

class attendance_sheet_settings {
    protected $attendanceitems;
    protected $data;
    protected $defaultjsonfile;

    public function __construct() {
        global $CFG, $USER;

        // Define the folder and file path for storing the default attendance sheet settings.
        // The folder is created under $CFG->dataroot to ensure it persists and is not publicly accessible.
        $datafolder = $CFG->dataroot . '/mod_facetoface';
        if (!is_dir($datafolder)) {
            mkdir($datafolder, 0700, true); // create folder with restricted permissions.
        }
        $this->defaultjsonfile = $datafolder . '/attendance_sheet_config_' . $USER->id . '.json';

        // Define the default attendance items array.
        $defaultData = [];

        // If the JSON file does not exist, create it with the default data.
        if (!file_exists($this->defaultjsonfile)) {
            file_put_contents($this->defaultjsonfile, json_encode($defaultData, JSON_PRETTY_PRINT));
            // Set file permissions so that only the owner can read and write.
            chmod($this->defaultjsonfile, 0600);
        }

        // Read and decode the JSON file to get attendance items.
        $jsoncontent = file_get_contents($this->defaultjsonfile);
        $decoded = json_decode($jsoncontent, true);
        if (is_array($decoded)) {
            $this->attendanceitems = $decoded;
        } else {
            // Fallback to default data if JSON decoding fails.
            $this->attendanceitems = $defaultData;
        }

        // Map the attendance_sheet table GUI header names to their corresponding enum values.
        $attendance_columns_enums_to_names = attendance_sheet_column::map_attendance_columns_enums_to_names();

        // Process each attendance item.
        foreach ($this->attendanceitems as &$item) {
            if (isset($item['labels'][0]['value'])) {
                $column = $item['labels'][0]['value'];
                // If the saved column is numeric (i.e. an enum value), convert it back to its display string.
                if (is_numeric($column) && isset($attendance_columns_enums_to_names[$column])) {
                    $column = $attendance_columns_enums_to_names[$column];
                    $item['labels'][0]['value'] = $column;
                }
                if ($column === 'Header Only' || $column === 'Header and Rows') {
                    $item['editable'] = true;
                    $item['defaultvalue'] = isset($item['labels'][1]['value']) ? $item['labels'][1]['value'] : '';
                    // Preserve the original type to know which default value to save.
                    $item['type'] = $column;
                } else {
                    $item['editable'] = false;
                }
            }
        }

        // Prepare context data for the mustache template.
        $this->data = [
            'uniqid'             => uniqid(),
            'header_text_column' => get_string('headertextcolumn', 'facetoface'),
            'header_text_value'  => get_string('headertextdefaultvalue', 'facetoface'),
            'empty_table'        => empty($this->attendanceitems),
            'attendance_items'   => $this->attendanceitems,
        ];
    }

    /**
     * Renders the attendance sheet settings table and associated JavaScript.
     *
     * @return string The rendered HTML output.
     */
    public function render() {
        global $OUTPUT;
        $output = '';

        // Render the table using the Mustache template.
        $output .= $OUTPUT->render_from_template('mod_facetoface/attendance_sheet_config_table', $this->data);

        // Prepare the exact HTML for the Moodle drag handle partial.
        $draghandlehtml = $OUTPUT->render_from_template('core/drag_handle', []);

        // Prepare the delete icon + alt text (like {{#pix}} t/delete, core, ...).
        $removerowicon = $OUTPUT->pix_icon('t/delete', get_string('modform:removerow', 'facetoface'), 'core');
        $removerowtext = '';

        // Append inline JavaScript for row deletion, drag-and-drop, adding new rows, and saving config via AJAX.
        $output .= '<script>
            document.addEventListener("DOMContentLoaded", function() {
                var table = document.querySelector("[data-attendance-sheet-config-table]");
                if (!table) {
                    return;
                }
                var tbody = table.querySelector("[data-attendance-sheet-config-items]");
                if (!tbody) {
                    return;
                }

                // Provide the drag handle + delete icon HTML to the JS code.
                var dragHandleHtml = ' . json_encode($draghandlehtml) . ';
                var removeRowIconHtml = ' . json_encode($removerowicon) . ';
                var removeRowText = ' . json_encode($removerowtext) . ';

                // === Row Deletion ===
                tbody.addEventListener("click", function(e) {
                    if (e.target.closest("[data-attendance-sheet-config-remove-row]")) {
                        e.preventDefault();
                        var row = e.target.closest("[data-attendance-sheet-config-item]");
                        if (row) {
                            row.remove();
                        }
                        // If no rows remain, show the "empty" row.
                        if (!tbody.querySelector("[data-attendance-sheet-config-item]")) {
                            var emptyRow = tbody.querySelector(".empty");
                            if (emptyRow) {
                                emptyRow.hidden = false;
                            }
                        }
                    }
                });

                // === Drag and Drop ===
                var dragSrc = null;

                function handleDragStart(e) {
                    dragSrc = this;
                    e.dataTransfer.effectAllowed = "move";
                    e.dataTransfer.setData("text/plain", null);
                    this.classList.add("dragging");
                }

                function handleDragOver(e) {
                    if (e.preventDefault) { e.preventDefault(); }
                    e.dataTransfer.dropEffect = "move";
                    return false;
                }

                function handleDragEnter() {
                    this.classList.add("over");
                }

                function handleDragLeave() {
                    this.classList.remove("over");
                }

                function handleDrop(e) {
                    if (e.stopPropagation) { e.stopPropagation(); }
                    if (dragSrc !== this) {
                        var srcHTML = dragSrc.innerHTML;
                        dragSrc.innerHTML = this.innerHTML;
                        this.innerHTML = srcHTML;
                    }
                    return false;
                }

                function handleDragEnd() {
                    document.querySelectorAll("[data-attendance-sheet-config-item]").forEach(function(row) {
                        row.classList.remove("over", "dragging");
                    });
                }

                function bindDragEvents(row) {
                    row.setAttribute("draggable", "true");
                    row.addEventListener("dragstart", handleDragStart, false);
                    row.addEventListener("dragenter", handleDragEnter, false);
                    row.addEventListener("dragover", handleDragOver, false);
                    row.addEventListener("dragleave", handleDragLeave, false);
                    row.addEventListener("drop", handleDrop, false);
                    row.addEventListener("dragend", handleDragEnd, false);
                }

                // Initially bind drag events for existing rows.
                tbody.querySelectorAll("[data-attendance-sheet-config-item]").forEach(function(row) {
                    bindDragEvents(row);
                });

                // === Dropdown for Adding Rows ===
                var addRowDropdown = table.querySelector("[data-attendance-sheet-config-add-row]");
                if (addRowDropdown) {
                    addRowDropdown.addEventListener("change", function(e) {
                        var selectedValue = e.target.value;
                        if (!selectedValue) {
                            return;
                        }
                        e.target.value = "";
                        var emptyRow = tbody.querySelector(".empty");
                        if (emptyRow) {
                            emptyRow.hidden = true;
                        }
                        var rowId = Date.now();
                        var tr = document.createElement("tr");
                        tr.setAttribute("data-attendance-sheet-config-item", "");
                        tr.setAttribute("data-value", rowId);
                        // For changeable rows, store the original type.
                        if (selectedValue === "Header Only" || selectedValue === "Header and Rows") {
                            tr.setAttribute("data-changeable", selectedValue);
                        }

                        var td1 = document.createElement("td");
                        // If the selected value is "Header Only" or "Header and Row", create an input field for column name.
                        if (selectedValue === "Header Only" || selectedValue === "Header and Rows") {
                            td1.innerHTML =
                                \'<input name="item_ids[]" type="hidden" value="\' + rowId + \'" />\' +
                                dragHandleHtml +
                                \'<input type="text" name="column_names[]" placeholder="Enter column name" />\';
                        } else {
                            td1.innerHTML =
                                \'<input name="item_ids[]" type="hidden" value="\' + rowId + \'" />\' +
                                dragHandleHtml +
                                selectedValue;
                        }

                        var td2 = document.createElement("td");
                        // For "Header Only", no default value input; for "Header and Rows", include a default value input.
                        if (selectedValue === "Header Only") {
                            td2.innerHTML = "";
                        } else if (selectedValue === "Header and Rows") {
                            td2.innerHTML = \'<input type="text" name="header_values[]" placeholder="Enter default value" />\';
                        } else if (["Name","Payroll","Email","Signature"].indexOf(selectedValue) !== -1) {
                            td2.innerHTML = "";
                        } else {
                            td2.innerHTML = \'<input type="text" name="header_values[]" />\';
                        }

                        var td3 = document.createElement("td");
                        td3.classList.add("action-column");
                        td3.innerHTML =
                            \'<a href="#" data-attendance-sheet-config-remove-row data-remove-value="\' + rowId + \'">\' +
                            removeRowIconHtml + " " + removeRowText +
                            \'</a>\';
                        tr.appendChild(td1);
                        tr.appendChild(td2);
                        tr.appendChild(td3);
                        tbody.appendChild(tr);
                        bindDragEvents(tr);
                    });
                }

                // === Save Config Button Handling via AJAX ===
                var saveButton = document.getElementById("save-config");
                if (saveButton) {
                    saveButton.addEventListener("click", function(e) {
                        // Gather config data from the table.
                        var configData = [];
                        tbody.querySelectorAll("[data-attendance-sheet-config-item]").forEach(function(row) {
                            var id = row.getAttribute("data-value");
                            var cells = row.querySelectorAll("td");
                            var columnName = "";
                            // Check if there is an input for the column name (for editable rows).
                            var inputCol = cells[0].querySelector("input[name=\'column_names[]\']");
                            if (inputCol) {
                                columnName = inputCol.value.trim();
                            } else {
                                columnName = cells[0].textContent.trim();
                            }
                            var defaultValue = "";
                            var input = cells[1].querySelector("input");
                            if (input) {
                                defaultValue = input.value.trim();
                            } else {
                                defaultValue = cells[1].textContent.trim();
                            }
                            var item = {
                                id: id,
                                labels: [
                                    { value: columnName, first: true }
                                ]
                            };
                            // Use the stored type (data-changeable) to decide if the default value should be saved.
                            var changeableType = row.getAttribute("data-changeable");
                            if (changeableType === "Header and Rows") {
                                item.labels.push({ value: defaultValue });
                            } else {
                                item.labels.push({ value: "" });
                            }
                            configData.push(item);
                        });
                        // Send the config data via AJAX to save_config.php.
                        var xhr = new XMLHttpRequest();
                        xhr.open("POST", "/mod/facetoface/save_config.php", true);
                        xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
                        xhr.onreadystatechange = function() {
                            if (xhr.readyState === 4) {
                                if (xhr.status === 200) {
                                    alert("Configuration saved successfully.");
                                } else {
                                    alert("Failed to save configuration.");
                                }
                            }
                        };
                        var sesskey = "";
                        if (typeof M !== "undefined" && M.cfg && M.cfg.sesskey) {
                            sesskey = M.cfg.sesskey;
                        }
                        var params = "sesskey=" + encodeURIComponent(sesskey) + "&config_data=" + encodeURIComponent(JSON.stringify(configData));
                        xhr.send(params);
                    });
                }
            });
        </script>';

        return $output;
    }

    /**
     * Inserts the rendered attendance sheet settings into the given Moodle form.
     *
     * @param moodleform $mform The Moodle form instance.
     */
    public function add_to_form(&$mform) {
        $mform->addElement('html', $this->render());
    }
}