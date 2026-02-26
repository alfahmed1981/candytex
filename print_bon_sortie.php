<?php
session_start();
require 'db.php';
require 'includes/auth.php';

if (!isset($_SESSION['user_cin'])) {
    die("Unauthorized access.");
}

$id = $_GET['id'] ?? null;
if (!$id) {
    die("Invalid request. Missing ID.");
}

// Fetch Autorisation Details
$stmt = $pdo->prepare("SELECT a.*, e.matricule, e.full_name, e.function_title, e.department, e.cin 
                       FROM hr_absences a 
                       JOIN hr_employees e ON a.employee_id = e.id 
                       WHERE a.id = ? AND a.absence_type = 'AUT'");
$stmt->execute([$id]);
$absence = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$absence) {
    die("Autorisation not found or is of a different type.");
}
?>
<!DOCTYPE html>
<html lang="fr" dir="ltr">

<head>
    <meta charset="UTF-8">
    <title>Bon de Sortie -
        <?= htmlspecialchars($absence['full_name']) ?>
    </title>
    <style>
        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            color: #333;
            margin: 0;
            padding: 20mm;
            background: #fff;
        }

        .container {
            max-width: 800px;
            margin: 0 auto;
            border: 2px solid #0b3c5d;
            padding: 20px;
            border-radius: 8px;
            position: relative;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 2px solid #0b3c5d;
            padding-bottom: 15px;
            margin-bottom: 20px;
        }

        .header h1 {
            color: #0b3c5d;
            margin: 0;
            font-size: 24px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .header .company {
            text-align: right;
            font-size: 14px;
            font-weight: bold;
            color: #555;
        }

        .details-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px 30px;
            margin-bottom: 30px;
        }

        .detail-row {
            display: flex;
            flex-direction: column;
        }

        .detail-label {
            font-size: 12px;
            font-weight: bold;
            color: #666;
            margin-bottom: 4px;
            text-transform: uppercase;
        }

        .detail-value {
            font-size: 16px;
            color: #000;
            padding: 8px;
            background: #f9f9f9;
            border: 1px solid #ddd;
            border-radius: 4px;
            min-height: 20px;
        }

        .full-width {
            grid-column: 1 / -1;
        }

        .signatures {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 20px;
            margin-top: 40px;
            text-align: center;
        }

        .signature-box {
            border: 1px solid #0b3c5d;
            border-radius: 4px;
            height: 120px;
            position: relative;
        }

        .signature-title {
            background: #0b3c5d;
            color: #fff;
            padding: 5px;
            font-size: 13px;
            font-weight: bold;
        }

        .security-notes {
            margin-top: 10px;
            font-size: 11px;
            color: #555;
            text-align: left;
            padding: 0 10px;
        }

        .footer {
            margin-top: 30px;
            text-align: center;
            font-size: 12px;
            color: #777;
            border-top: 1px solid #eee;
            padding-top: 10px;
        }

        @media print {
            body {
                padding: 0;
            }

            .container {
                border: none;
            }

            button {
                display: none;
            }
        }
    </style>
</head>

<body onload="window.print()">
    <div style="text-align:right; margin-bottom:10px;">
        <button onclick="window.print()"
            style="padding:10px 20px; background:#0b3c5d; color:white; border:none; border-radius:4px; cursor:pointer;">
            🖨️ Imprimer / Print
        </button>
    </div>

    <div class="container">
        <div class="header">
            <div>
                <h1>BON DE SORTIE</h1>
                <div style="font-size: 14px; color: #666; margin-top: 5px;">Autorisation Exceptionnelle / رخصة خروج
                </div>
            </div>
            <div class="company">
                CandyTex S.A.R.L<br>
                <small>Service des Ressources Humaines</small>
            </div>
        </div>

        <div class="details-grid">
            <div class="detail-row">
                <span class="detail-label">Nom et Prénom / الإسم الكامل</span>
                <span class="detail-value">
                    <?= htmlspecialchars($absence['full_name']) ?>
                </span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Matricule / الرقم</span>
                <span class="detail-value">
                    <?= htmlspecialchars($absence['matricule']) ?>
                </span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Fonction / المهنة</span>
                <span class="detail-value">
                    <?= htmlspecialchars($absence['function_title'] ?: '-') ?>
                </span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Département / القسم</span>
                <span class="detail-value">
                    <?= htmlspecialchars($absence['department'] ?: '-') ?>
                </span>
            </div>

            <div class="detail-row full-width" style="margin-top:20px; border-top: 1px dashed #ccc; padding-top:20px;">
                <span class="detail-label">Date de l'autorisation / تاريخ الرخصة</span>
                <span class="detail-value" style="font-weight:bold; background:#e3f2fd; border-color:#90caf9;">
                    <?= date('d / m / Y', strtotime($absence['start_date'])) ?>
                </span>
            </div>

            <div class="detail-row">
                <span class="detail-label">Heure de Sortie / ساعة الخروج</span>
                <span class="detail-value" style="font-weight:bold;">
                    <?= $absence['exit_time'] ? date('H:i', strtotime($absence['exit_time'])) : 'N/A' ?>
                </span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Heure de Retour Prévue / ساعة الدخول المتوقعة</span>
                <span class="detail-value" style="font-weight:bold;">
                    <?= $absence['return_time'] ? date('H:i', strtotime($absence['return_time'])) : 'N/A' ?>
                </span>
            </div>

            <div class="detail-row full-width">
                <span class="detail-label">Motif / السبب</span>
                <span class="detail-value" style="min-height: 40px;">
                    <?= htmlspecialchars($absence['comments'] ?: 'Non spécifié') ?>
                </span>
            </div>
        </div>

        <div class="signatures">
            <div class="signature-box">
                <div class="signature-title">Visa RH / الموارد البشرية</div>
            </div>
            <div class="signature-box">
                <div class="signature-title">Visa Responsable / المسؤول</div>
            </div>
            <div class="signature-box">
                <div class="signature-title">Visa Sécurité / الأمن</div>
                <div class="security-notes">
                    Heure sortie réelle: _____<br><br>
                    Heure retour réelle: _____
                </div>
            </div>
        </div>

        <div class="footer">
            Ce document doit être obligatoirement visé par la hiérarchie et remis à l'agent de sécurité au portail lors
            de la sortie.<br>
            يجب توقيع هذه الوثيقة من المسؤول وتسليمها لحارس الأمن عند الخروج.
        </div>
    </div>
</body>

</html>