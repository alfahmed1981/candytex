// Initial Render of Countermeasures
document.addEventListener('DOMContentLoaded', () => {
    if (typeof initialCM !== 'undefined') {
        renderTable(initialCM);
    }
    // Create modal dynamically if it doesn't exist
    createStatusModal();
});

// --- CREATE STATUS MODAL DYNAMICALLY ---
function createStatusModal() {
    if (document.getElementById('statusModal')) return;

    const modal = document.createElement('div');
    modal.id = 'statusModal';
    modal.style.cssText = 'display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); justify-content:center; align-items:center; z-index:1000;';

    modal.innerHTML = `
        <div style="background:white; padding:30px; border-radius:15px; text-align:center; max-width:400px; width:90%;">
            <h3 id="modalTitle" style="margin-bottom:20px;">Update Status</h3>
            <div style="display:grid; grid-template-columns:repeat(2,1fr); gap:10px; margin-bottom:20px;">
                <button onclick="selectStatus('green')" style="background:#28a745; color:white; padding:15px; border:none; border-radius:8px; cursor:pointer; font-size:16px;">
                    ✓ موافق<br><small>Met / Conforme</small>
                </button>
                <button onclick="selectStatus('orange')" style="background:#fd7e14; color:white; padding:15px; border:none; border-radius:8px; cursor:pointer; font-size:16px;">
                    ⚠️ إجراء<br><small>Action Required</small>
                </button>
                <button onclick="selectStatus('red')" style="background:#dc3545; color:white; padding:15px; border:none; border-radius:8px; cursor:pointer; font-size:16px;">
                    ✗ خطر<br><small>Missed / Accident</small>
                </button>
                <button onclick="selectStatus('blue')" style="background:#007bff; color:white; padding:15px; border:none; border-radius:8px; cursor:pointer; font-size:16px;">
                    📅 عطلة<br><small>Holiday / N.A.</small>
                </button>
            </div>
            <button onclick="selectStatus('gray')" style="background:#6c757d; color:white; padding:10px 30px; border:none; border-radius:8px; cursor:pointer; margin-bottom:15px;">
                ○ مسح<br><small>Clear</small>
            </button>
            <br>
            <button onclick="closeModal()" style="background:#eee; color:#333; padding:10px 30px; border:none; border-radius:8px; cursor:pointer;">
                إلغاء / Cancel
            </button>
        </div>
    `;

    document.body.appendChild(modal);
}

// --- GLOBAL FUNCTION CALLED BY INDEX.PHP ---
function openDate(category, dateKey, currentStatus) {
    // Parse the date from the dateKey (format: YYYY-M-D)
    const parts = dateKey.split('-');
    const year = parseInt(parts[0]);
    const month = parseInt(parts[1]);
    const day = parseInt(parts[2]);

    openModal(category, day, year, month);
}

// --- MODAL LOGIC FOR DAY SELECTION ---
let currentDay = null;
let currentCategory = null;

function openModal(type, day, currentYear, currentMonth) {
    // --- DATE VALIDATION LOGIC ---
    const today = new Date();
    today.setHours(0, 0, 0, 0); // Start of today

    const selectedDate = new Date(currentYear, currentMonth - 1, day);

    // 1. Prevent Future Days
    if (selectedDate > today) {
        Swal.fire({
            icon: 'warning',
            title: 'Future Date / تاريخ مستقبلي',
            text: 'You cannot fill reporting for future days. / لا يمكنك ملء التقارير للأيام المستقبلية.'
        });
        return;
    }

    // 2. Prevent Old Days (> 7 days)
    const diffTime = Math.abs(today - selectedDate);
    const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));

    if (diffDays > 7) {
        Swal.fire({
            icon: 'error',
            title: 'Locked / مقفل',
            text: 'You can only edit data for the last 7 days. / يمكنك فقط تعديل البيانات للأيام السبعة الماضية.'
        });
        return;
    }

    currentDay = day;
    currentCategory = type;

    // Update Modal Title (Trilingual)
    const catNames = {
        'S': 'Safety / السلامة',
        'Q': 'Quality / الجودة',
        'D': 'Delivery / التسليم',
        '5S': '5S / التحسين',
        'C': 'Cost / التكلفة'
    };

    const dateStr = `${currentYear}-${currentMonth}-${day}`;
    document.getElementById('modalTitle').innerHTML = `Update <strong>${catNames[type] || type}</strong> <br> <small>${dateStr}</small>`;

    // Show Modal
    document.getElementById('statusModal').style.display = 'flex';
}

function closeModal() {
    document.getElementById('statusModal').style.display = 'none';
}

function selectStatus(status) {
    closeModal();

    const year = document.getElementById('currentYear')?.value || new Date().getFullYear();
    const month = document.getElementById('currentMonth')?.value || (new Date().getMonth() + 1);
    const dateStr = `${year}-${month}-${currentDay}`;

    fetch('api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action: 'update_day',
            kpi: currentCategory,
            date: dateStr,
            status: status
        })
    })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                Swal.fire('Error', data.message || 'Could not save', 'error');
            }
        })
        .catch(err => {
            Swal.fire('Error', 'Network error', 'error');
        });
}

// --- COUNTERMEASURES LOGIC ---
let cmData = typeof initialCM !== 'undefined' ? initialCM : [];

// Predefined Issues for each category (Dynamic Dropdown)
const predefinedIssues = {
    'S': [
        { value: 'ppe_damaged', label: '🦺 عطل في معدات الوقاية (PPE Damaged)' },
        { value: 'slip_trip', label: '⚠️ انزلاق/تعثر (Slip/Trip Hazard)' },
        { value: 'minor_injury', label: '🩹 إصابة عمل خفيفة (Minor Injury)' },
        { value: 'blocked_exit', label: '🚫 ممر مسدود (Blocked Exit)' },
        { value: 'electrical_hazard', label: '⚡ خطر كهربائي (Electrical Hazard)' },
        { value: 'other', label: '📝 أخرى (Other)' }
    ],
    'Q': [
        { value: 'sewing_defect', label: '🧵 عيب في الخياطة (Sewing Defect)' },
        { value: 'measurement_issue', label: '📏 مشكلة في القياسات (Measurement Issue)' },
        { value: 'stains_dirt', label: '🔴 بقع/أوساخ (Stains/Dirt)' },
        { value: 'fabric_mismatch', label: '🎨 خطأ في القماش/اللون (Fabric Mismatch)' },
        { value: 'accessories_defect', label: '🔘 عيب في الملحقات (Accessories Defect)' },
        { value: 'other', label: '📝 أخرى (Other)' }
    ],
    'D': [
        { value: 'material_delay', label: '📦 تأخر المواد الأولية (Material Delay)' },
        { value: 'machine_breakdown', label: '🔧 عطل في الآلة (Machine Breakdown)' },
        { value: 'absenteeism', label: '👷 غياب المشغلين (Absenteeism)' },
        { value: 'bottleneck', label: '⏳ عنق زجاجة (Process Bottleneck)' },
        { value: 'power_failure', label: '💡 انقطاع التيار (Power Failure)' },
        { value: 'other', label: '📝 أخرى (Other)' }
    ],
    '5S': [
        { value: 'items_not_in_place', label: '📍 أدوات غير مرتبة (Items Not in Place)' },
        { value: 'workstation_dirty', label: '🧹 نظافة المكان (Workstation Dirty)' },
        { value: 'fading_lines', label: '📐 ممرات غير مخططة (Fading Floor Lines)' },
        { value: 'excess_inventory', label: '📚 تكدس المخزون (Excess Inventory)' },
        { value: 'missing_labels', label: '🏷️ غياب الملصقات (Missing Labels)' },
        { value: 'other', label: '📝 أخرى (Other)' }
    ],
    'C': [
        { value: 'material_waste', label: '🗑️ هدر في المواد (Material Waste)' },
        { value: 'excess_energy', label: '💡 استهلاك طاقة زائد (Excess Energy)' },
        { value: 'unplanned_overtime', label: '⏰ عمل إضافي غير مبرر (Unplanned Overtime)' },
        { value: 'rework_cost', label: '🔄 إصلاحات متكررة (Rework Cost)' },
        { value: 'tool_breakage', label: '🔨 تلف أدوات (Tool Breakage)' },
        { value: 'other', label: '📝 أخرى (Other)' }
    ]
};

// Predefined Actions for each category (Cascading Dropdown)
const predefinedActions = {
    'S': [
        { label: '🦺 استبدال معدات الوقاية (Replace PPE)' },
        { label: '🧹 تنظيف انسكاب (Clean Spill)' },
        { label: '⚠️ وضع علامة تحذيرية (Place Warning Sign)' },
        { label: '🚫 إزالة العائق (Clear Obstruction)' },
        { label: '🩹 إسعاف أولي (First Aid Given)' },
        { label: '🔒 عزل المنطقة (Isolate Area)' },
        { label: '📝 أخرى (Other)' }
    ],
    'Q': [
        { label: '🔄 إعادة العمل (Rework)' },
        { label: '🗑️ إتلاف/خردة (Scrap)' },
        { label: '📦 عزل المنتج (Quarantine)' },
        { label: '⚙️ تعديل ضبط الآلة (Adjust Machine)' },
        { label: '📢 تنبيه الجودة (Quality Alert)' },
        { label: '🔍 فرز 100% (100% Sorting)' },
        { label: '📝 أخرى (Other)' }
    ],
    'D': [
        { label: '🔧 طلب صيانة عاجل (Call Maintenance)' },
        { label: '📦 استخدام مخزون الأمان (Use Buffer Stock)' },
        { label: '⏰ ساعات إضافية (Overtime)' },
        { label: '👥 إعادة توزيع العمال (Reassign Operators)' },
        { label: '🚚 تسريع المواد (Expedite Material)' },
        { label: '📝 أخرى (Other)' }
    ],
    '5S': [
        { label: '🧹 تنظيف فوري (Clean Immediately)' },
        { label: '📍 إعادة للمكان (Return to Home)' },
        { label: '🏷️ طباعة ملصق (Print Label)' },
        { label: '🔴 وضع بطاقة حمراء (Red Tagging)' },
        { label: '📐 تخطيط الحدود (Mark Boundaries)' },
        { label: '📝 أخرى (Other)' }
    ],
    'C': [
        { label: '🔌 إيقاف التشغيل (Shutdown)' },
        { label: '🔍 تحقيق في السبب (Investigate Root Cause)' },
        { label: '♻️ استرجاع/إعادة استخدام (Reuse/Recover)' },
        { label: '📊 مراجعة التخطيط (Review Planning)' },
        { label: '📝 أخرى (Other)' }
    ]
};

// Predefined Responsible Roles for each category
const predefinedResponsible = {
    'S': [
        { label: '👷 رئيس الفريق (Team Leader)' },
        { label: '🦺 مسؤول السلامة (HSE Officer)' },
        { label: '🔧 عامل الصيانة (Maintenance Tech)' },
        { label: '🧹 عامل النظافة (Cleaner)' },
        { label: '👔 مشرف الإنتاج (Supervisor)' }
    ],
    'Q': [
        { label: '🔍 مراقب الجودة (Quality Controller)' },
        { label: '👷 رئيس الفريق (Team Leader)' },
        { label: '⚙️ المكتب التقني (Technical/Methods)' },
        { label: '👔 مشرف الإنتاج (Supervisor)' }
    ],
    'D': [
        { label: '🔧 عامل الصيانة (Maintenance Tech)' },
        { label: '📦 مسؤول المخزن (Storekeeper)' },
        { label: '👷 رئيس الفريق (Team Leader)' },
        { label: '👔 مشرف الإنتاج (Supervisor)' },
        { label: '👥 إعادة توزيع (HR/Planning)' }
    ],
    '5S': [
        { label: '👷 رئيس الفريق (Team Leader)' },
        { label: '🧹 عامل النظافة (Cleaner)' },
        { label: '📦 مسؤول المخزن (Storekeeper)' },
        { label: '⚙️ المكتب التقني (Technical/Methods)' }
    ],
    'C': [
        { label: '👔 مشرف الإنتاج (Supervisor)' },
        { label: '👷 رئيس الفريق (Team Leader)' },
        { label: '🔧 عامل الصيانة (Maintenance Tech)' },
        { label: '📊 المحاسبة/الإدارة (Admin/Finance)' }
    ]
};

function getOptions(list, selectedValue, placeholder) {
    let options = `<option value="">${placeholder}</option>`;

    list.forEach(item => {
        const selected = selectedValue === item.label ? 'selected' : '';
        options += `<option value="${item.label}" ${selected}>${item.label}</option>`;
    });

    // Check if current value is custom (not in predefined list)
    if (selectedValue && !list.some(i => i.label === selectedValue)) {
        options += `<option value="${selectedValue}" selected>📝 ${selectedValue}</option>`;
    }

    return options;
}

function getIssueOptions(category, selectedValue) {
    return getOptions(predefinedIssues[category] || [], selectedValue, '-- اختر المشكلة --');
}

function getActionOptions(category, selectedValue) {
    return getOptions(predefinedActions[category] || [], selectedValue, '-- اختر الإجراء --');
}

function getResponsibleOptions(category, selectedValue) {
    return getOptions(predefinedResponsible[category] || [], selectedValue, '-- اختر المسؤول --');
}

function renderTable(data) {
    cmData = data;
    const tbody = document.querySelector('#cm-table tbody');
    if (!tbody) return;

    tbody.innerHTML = '';

    cmData.forEach((row, index) => {
        const tr = document.createElement('tr');
        const category = row.category || 'S';

        // Category Select
        const catOptions = ['S', 'Q', 'D', '5S', 'C'].map(c =>
            `<option value="${c}" ${category === c ? 'selected' : ''}>${c}</option>`
        ).join('');

        // Dynamic Dropdowns based on category
        const issueOptions = getIssueOptions(category, row.issue);
        const actionOptions = getActionOptions(category, row.action_plan);
        const responsibleOptions = getResponsibleOptions(category, row.responsible);

        tr.innerHTML = `
            <td>
                <select onchange="updateCategory(${index}, this.value)" style="font-weight:bold; min-width:60px;">
                    ${catOptions}
                </select>
            </td>
            <td>
                <select onchange="updateRow(${index}, 'issue', this.value)" style="min-width:180px; font-size:11px;">
                    ${issueOptions}
                </select>
            </td>
            <td>
                <select onchange="updateRow(${index}, 'action_plan', this.value)" style="min-width:160px; font-size:11px;">
                    ${actionOptions}
                </select>
            </td>
            <td>
                <select onchange="updateRow(${index}, 'responsible', this.value)" style="min-width:140px; font-size:11px;">
                    ${responsibleOptions}
                </select>
            </td>
            <td><input type="date" value="${row.due_date || ''}" onchange="updateRow(${index}, 'due_date', this.value)"></td>
            <td>
                <select onchange="updateRow(${index}, 'status', this.value)">
                    <option value="Open" ${row.status === 'Open' ? 'selected' : ''}>Open</option>
                    <option value="In Progress" ${row.status === 'In Progress' ? 'selected' : ''}>In Progress</option>
                    <option value="Done" ${row.status === 'Done' ? 'selected' : ''}>Done</option>
                </select>
            </td>
            <td><button style="background:red; padding:5px; color:white; border:none; cursor:pointer;" onclick="deleteRow(${index})">X</button></td>
        `;
        tbody.appendChild(tr);
    });
}

// Update category and refresh all cascading dropdowns
function updateCategory(index, newCategory) {
    cmData[index].category = newCategory;
    cmData[index].issue = ''; // Reset when category changes
    cmData[index].action_plan = ''; // Reset when category changes
    cmData[index].responsible = ''; // Reset when category changes
    renderTable(cmData);
    saveCM();
}

function addCounterMeasure() {
    cmData.push({ category: 'S', issue: '', action_plan: '', responsible: '', due_date: '', status: 'Open' });
    renderTable(cmData);
    saveCM();
}

function updateRow(index, field, value) {
    cmData[index][field] = value;
    saveCM();
}

function deleteRow(index) {
    cmData.splice(index, 1);
    renderTable(cmData);
    saveCM();
}

function saveCM() {
    fetch('api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action: 'save_countermeasures',
            data: cmData
        })
    });
}

// --- MOBILE SIDEBAR TOGGLE ---
function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.querySelector('.sidebar-overlay');
    sidebar.classList.toggle('open');
    overlay.classList.toggle('active');
    document.body.style.overflow = sidebar.classList.contains('open') ? 'hidden' : '';
}

function closeSidebar() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.querySelector('.sidebar-overlay');
    sidebar.classList.remove('open');
    overlay.classList.remove('active');
    document.body.style.overflow = '';
}

// Close sidebar on window resize (if desktop)
window.addEventListener('resize', function () {
    if (window.innerWidth > 768) {
        closeSidebar();
    }
});
