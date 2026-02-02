// Initial Render of Countermeasures
document.addEventListener('DOMContentLoaded', () => {
    if (typeof initialCM !== 'undefined') {
        renderTable(initialCM);
    }
});

// --- DATE LOGIC ---
function openDate(kpi, date, currentStatus) {
    Swal.fire({
        title: `Update ${kpi} - ${date}`,
        html: `
            <div style="display: flex; gap: 10px; justify-content: center; flex-wrap: wrap;">
                <button class="swal2-confirm swal2-styled" style="background-color: #28a745;" onclick="selectStatus('${kpi}', '${date}', 'green')">Met (Green)</button>
                <button class="swal2-confirm swal2-styled" style="background-color: #fd7e14;" onclick="selectStatus('${kpi}', '${date}', 'orange')">Action Req</button>
                <button class="swal2-confirm swal2-styled" style="background-color: #dc3545;" onclick="selectStatus('${kpi}', '${date}', 'red')">Missed/Accident</button>
                <button class="swal2-confirm swal2-styled" style="background-color: #007bff;" onclick="selectStatus('${kpi}', '${date}', 'blue')">Holiday</button>
                <button class="swal2-confirm swal2-styled" style="background-color: #e9ecef; color: #333;" onclick="selectStatus('${kpi}', '${date}', 'gray')">Clear</button>
            </div>
        `,
        showConfirmButton: false,
        showCancelButton: true
    });
}

function selectStatus(kpi, date, status) {
    Swal.close(); // Close modal

    // Optimistic UI Update
    // Find the specific box and update class
    // (A full page reload is safest for PHP rendering, but let's try JS update first)

    fetch('api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action: 'update_day',
            kpi: kpi,
            date: date,
            status: status
        })
    })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                location.reload(); // Simple reload to reflect changes
            } else {
                Swal.fire('Error', 'Could not save', 'error');
            }
        });
}

// --- COUNTERMEASURES LOGIC ---
let cmData = typeof initialCM !== 'undefined' ? initialCM : [];

function renderTable(data) {
    cmData = data;
    const tbody = document.querySelector('#cm-table tbody');
    tbody.innerHTML = '';

    cmData.forEach((row, index) => {
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td contenteditable="true" onblur="updateRow(${index}, 'issue', this.innerText)">${row.issue || ''}</td>
            <td contenteditable="true" onblur="updateRow(${index}, 'action', this.innerText)">${row.action || ''}</td>
            <td contenteditable="true" onblur="updateRow(${index}, 'who', this.innerText)">${row.who || ''}</td>
            <td><input type="date" value="${row.due || ''}" onchange="updateRow(${index}, 'due', this.value)"></td>
            <td>
                <select onchange="updateRow(${index}, 'status', this.value)">
                    <option value="Open" ${row.status === 'Open' ? 'selected' : ''}>Open</option>
                    <option value="In Progress" ${row.status === 'In Progress' ? 'selected' : ''}>In Progress</option>
                    <option value="Done" ${row.status === 'Done' ? 'selected' : ''}>Done</option>
                </select>
            </td>
            <td><button style="background:red; padding:5px;" onclick="deleteRow(${index})">X</button></td>
        `;
        tbody.appendChild(tr);
    });
}

function addCounterMeasure() {
    cmData.push({ issue: '', action: '', who: '', due: '', status: 'Open' });
    renderTable(cmData);
    saveCM();
}

function updateRow(index, field, value) {
    cmData[index][field] = value;
    saveCM(); // Auto-save on blur
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
