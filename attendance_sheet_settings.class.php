<?php
/**
 * Sets up and renders the attendance sheet configuration.
 *
 * @package    mod_facetoface
 * @copyright  2025 Gold Coast Health
 * @author     Yucheng Zhu
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
global $CFG;

use mod_facetoface\enum\attendance_column;

require_once("$CFG->dirroot/mod/facetoface/classes/data/attendance_sheet_io.php");

defined('MOODLE_INTERNAL') || die();

class attendance_sheet_settings {
    protected $attendanceitems;
    protected $data;
    protected $jsonfile;

    public function __construct(int $instanceid) {
        global $CFG, $PAGE, $DB;

        $this->instanceid = $instanceid;

        $PAGE->requires->css(new moodle_url('/mod/facetoface/style/attendance_sheet_config_styles.css'));

        $jsonfile = get_attendance_config_file($CFG, $this->instanceid);

        $instance = $DB->get_record('facetoface', [ 'id' => $this->instanceid ]);

        $this->attendanceitems = return_updated_json_content($jsonfile, $instance);

        // Map the attendance_sheet table GUI header names to their corresponding enum values.
        $attendance_columns_enums_to_names = attendance_column::map_attendance_columns_enums_to_names();

        // Process each attendance item.
        foreach ($this->attendanceitems as &$item) {
            if (isset($item['column'])) {
                $column = $item['column'];

                if (is_int($column) && isset($attendance_columns_enums_to_names[$column])) {
                    $column = $attendance_columns_enums_to_names[$column];
                    $item['column'] = $column;
                }

                $item['editable'] = false;
                if ($column === 'Custom Column') {
                    $item['editable'] = true;
                    $item['value'] = isset($item['value']) ? $item['value'] : '';
                }
            }
        }

        // Prepare context data for the mustache template.
        $this->data = [
            'uniqid'             => uniqid(),
            'header_text_column' => get_string('headertextcolumn', 'facetoface'),
            'header_text_value'  => get_string('headertextrowvalue', 'facetoface'),
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
        $tablehtml = $OUTPUT->render_from_template('mod_facetoface/attendance_sheet_config_table', $this->data);
        // Wrap the table with a container that includes a left-hand label "Column config".
        $output .= '<div class="attendance-sheet-config__container form-group row fitem">';
        $output .= '<div class="attendance-sheet-config__label d-flex align-items-center flex-gap-1 inner edw-form-label d-inline">Column config</div>';
        $output .= '<div class="attendance-sheet-config__table">' . $tablehtml . '</div>';
        $output .= '</div>';

        // Prepare the exact HTML for the Moodle drag handle partial.
        $draghandlehtml = $OUTPUT->render_from_template('core/drag_handle', []);

        // Prepare the delete icon + alt text.
        $removerowicon = $OUTPUT->pix_icon('t/delete', get_string('modform:removerow', 'facetoface'), 'core');
        $removerowtext = '';

        // Append inline JavaScript for row deletion, drag-and-drop, adding new rows,
        // and saving config via AJAX. All variables outside functions are passed as parameters.
        $output .= '<script>
            document.addEventListener("DOMContentLoaded", () => {
                const tableElement = document.querySelector("[data-attendance-sheet-config-table]");
                if (!tableElement) {
                    return;
                }
                
                const tbodyElement = tableElement.querySelector("[data-attendance-sheet-config-items]");
                if (!tbodyElement) {
                    return;
                }
                
                const addRowDropdown = tableElement.querySelector("[data-attendance-sheet-config-add-row]");
                const saveButton = document.getElementById("save-config");
                const cancelButton = document.getElementById("cancel-config");

                const params = {
                    tableElement: tableElement,
                    tbodyElement: tbodyElement,
                    addRowDropdown: addRowDropdown,
                    saveButton: saveButton,
                    cancelButton: cancelButton,
                    dragHandleHtml: ' . json_encode($draghandlehtml) . ',
                    removeRowIconHtml: ' . json_encode($removerowicon) . ',
                    removeRowText: ' . json_encode($removerowtext) . ',
                    saveConfigUrl: "/mod/facetoface/save_config.php",
                    sesskey: (typeof M !== "undefined" && M.cfg && M.cfg.sesskey) ? M.cfg.sesskey : "",
                    instanceid: ' . json_encode($this->instanceid) . '
                };

                initAttendanceSheetConfig(params);
            });

            function initAttendanceSheetConfig(params) {
                initRowDeletion(params.tbodyElement);
                initDragAndDrop(params);
                if (params.addRowDropdown) {
                    initAddRowDropdown(params);
                }
                if (params.saveButton) {
                    initSaveButton(params);
                }
                if (params.cancelButton) {
                    initCancelButton(params);
                }
            }

            function initRowDeletion(tbodyElement) {
                tbodyElement.addEventListener("click", function(e) {
                    const removeRowTrigger = e.target.closest("[data-attendance-sheet-config-remove-row]");
                    if (!removeRowTrigger) {
                        return;
                    }
                    
                    e.preventDefault();
                    const row = removeRowTrigger.closest("[data-attendance-sheet-config-item]");
                    if (row) {
                        row.remove();
                    }
                    
                    if (!tbodyElement.querySelector("[data-attendance-sheet-config-item]")) {
                        const emptyRow = tbodyElement.querySelector(".empty");
                        if (emptyRow) {
                            emptyRow.hidden = false;
                        }
                    }
                });
            }

            function initDragAndDrop(params) {
                let dragSrc = null;

                const handleDragStart = function(e) {
                    dragSrc = e.currentTarget;
                    e.dataTransfer.effectAllowed = "move";
                    e.dataTransfer.setData("text/plain", null);
                    e.currentTarget.classList.add("dragging");
                };

                const handleDragOver = function(e) {
                    e.preventDefault();
                    e.dataTransfer.dropEffect = "move";
                    return false;
                };

                const handleDragEnter = function(e) {
                    e.currentTarget.classList.add("over");
                };

                const handleDragLeave = function(e) {
                    e.currentTarget.classList.remove("over");
                };

                const handleDrop = function(e) {
                    if (e.stopPropagation) {
                        e.stopPropagation();
                    }
                    if (dragSrc === e.currentTarget) {
                        return false;
                    }
                    
                    const target = e.currentTarget;
                    const targetRect = target.getBoundingClientRect();
                    const dropPosition = e.clientY - targetRect.top;
                    const insertBefore = dropPosition < (targetRect.height / 2);
                    
                    if (dragSrc.parentNode) {
                        dragSrc.parentNode.removeChild(dragSrc);
                    }
                    
                    if (insertBefore) {
                        target.parentNode.insertBefore(dragSrc, target);
                        return false;
                    }
                    
                    if (target.nextSibling) {
                        target.parentNode.insertBefore(dragSrc, target.nextSibling);
                    } else {
                        target.parentNode.appendChild(dragSrc);
                    }
                    return false;
                };

                const handleDragEnd = function(e) {
                    const rows = params.tbodyElement.querySelectorAll("[data-attendance-sheet-config-item]");
                    rows.forEach(function(row) { row.classList.remove("over", "dragging"); });
                };

                const bindDragEvents = function(row) {
                    row.setAttribute("draggable", "true");
                    row.addEventListener("dragstart", handleDragStart, false);
                    row.addEventListener("dragenter", handleDragEnter, false);
                    row.addEventListener("dragover", handleDragOver, false);
                    row.addEventListener("dragleave", handleDragLeave, false);
                    row.addEventListener("drop", handleDrop, false);
                    row.addEventListener("dragend", handleDragEnd, false);
                };

                const rows = params.tbodyElement.querySelectorAll("[data-attendance-sheet-config-item]");
                rows.forEach(function(row) { bindDragEvents(row); });
                params.bindDragEvents = bindDragEvents;
            }

            function initAddRowDropdown(params) {
                params.addRowDropdown.addEventListener("change", function(e) {
                    const selectedValue = e.target.value;
                    if (!selectedValue) {
                        return;
                    }
                    
                    e.target.value = "";
                    const emptyRow = params.tbodyElement.querySelector(".empty");
                    if (emptyRow) {
                        emptyRow.hidden = true;
                    }
                    
                    const rowId = Date.now();
                    const tr = document.createElement("tr");
                    tr.setAttribute("data-attendance-sheet-config-item", "");
                    tr.setAttribute("data-value", rowId);
                    
                    if (selectedValue === "Custom Column") {
                        tr.setAttribute("data-changeable", selectedValue);
                    }
                    
                    const td1 = document.createElement("td");
                    if (selectedValue === "Custom Column") {
                        td1.innerHTML =
                            \' <input name="item_ids[]" type="hidden" value="\' + rowId + \'" /> \' +
                            \' <span class="attendance-sheet-config__table__cell--first__input"> \' +
                                params.dragHandleHtml +
                                \' <input type="text" name="column_names[]" placeholder="Enter column name" class="form-control" /> \' +
                            \' </span>\';
                    } else {
                        td1.innerHTML =
                            \'<input name="item_ids[]" type="hidden" value="\' + rowId + \'" />\' +
                            params.dragHandleHtml +
                            selectedValue;
                    }
                    
                    const td2 = document.createElement("td");
                    if (selectedValue === "Custom Column") {
                        td2.innerHTML = \'<input type="text" name="header_values[]" placeholder="Enter default value" class="form-control" />\';
                    } else if (["Name", "Payroll", "Email", "Org Unit", "Position", "Stream", "Paypoint"].indexOf(selectedValue) !== -1) {
                        td2.innerHTML = "";
                    } else {
                        td2.innerHTML = \'<input type="text" name="header_values[]" />\';
                    }
                    
                    const td3 = document.createElement("td");
                    td3.classList.add("action-column");
                    td3.innerHTML =
                        \'<a href="#" data-attendance-sheet-config-remove-row data-remove-value="\' + rowId + \'">\' +
                        params.removeRowIconHtml + " " + params.removeRowText +
                        \'</a>\';
                    
                    tr.appendChild(td1);
                    tr.appendChild(td2);
                    tr.appendChild(td3);
                    params.tbodyElement.appendChild(tr);
                    
                    if (typeof params.bindDragEvents === "function") {
                        params.bindDragEvents(tr);
                    }
                });
            }

            function initSaveButton(params) {
                params.saveButton.addEventListener("click", function(e) {
                    const configData = [];
                    const rows = params.tbodyElement.querySelectorAll("[data-attendance-sheet-config-item]");
                    rows.forEach(function(row) {
                        const cells = row.querySelectorAll("td");
                        const inputCol = cells[0].querySelector("input[name=\'column_names[]\']");
                        const columnName = inputCol ? inputCol.value.trim() : cells[0].textContent.trim();
                        
                        const inputVal = cells[1].querySelector("input");
                        const defaultValue = inputVal ? inputVal.value.trim() : cells[1].textContent.trim();
                        
                        const item = {
                            column: columnName, 
                            value: defaultValue
                        };
                        configData.push(item);
                    });
                    
                    const xhr = new XMLHttpRequest();
                    xhr.open("POST", params.saveConfigUrl, false);
                    xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
                    xhr.onreadystatechange = function() {
                        if (xhr.readyState === 4) {
                            if (xhr.status === 200) {
                                alert("Configuration saved successfully.");
                            } else {
                                alert("Failed to save configuration. Status Code: " + xhr.status);
                            }
                        }
                    };
                    const paramsStr = "sesskey=" + encodeURIComponent(params.sesskey) +
                                      "&instanceid=" + encodeURIComponent(params.instanceid) +
                                      "&config_data=" + encodeURIComponent(JSON.stringify(configData));
                    xhr.send(paramsStr);
                });
            }

            function initCancelButton(params) {
                params.cancelButton.addEventListener("click", function(e) {
                    e.preventDefault();
                    if (confirm("Do you want to cancel the change? All unsaved items in the text field will be lost.")) {
                        location.reload();
                    }
                });
            }
        </script>';

        return $output;
    }
}
?>
