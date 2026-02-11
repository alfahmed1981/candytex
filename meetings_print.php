<?php
// meetings_print.php — Print views for meetings (included from meetings.php)
// Variables available: $meeting, $attendees, $agenda, $decisions, $print_type

$date_fr = date('d/m/Y', strtotime($meeting['meeting_date']));
$time_fr = substr($meeting['meeting_time'], 0, 5);
$days_ar = ['Sunday' => 'الأحد', 'Monday' => 'الاثنين', 'Tuesday' => 'الثلاثاء', 'Wednesday' => 'الأربعاء', 'Thursday' => 'الخميس', 'Friday' => 'الجمعة', 'Saturday' => 'السبت'];
$day_en = date('l', strtotime($meeting['meeting_date']));
$day_ar = $days_ar[$day_en] ?? $day_en;
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <title><?php
    $titles = ['invite' => 'استدعاء اجتماع', 'agenda' => 'جدول الأعمال', 'minutes' => 'محضر الاجتماع', 'attendance' => 'لائحة الحضور'];
    echo ($titles[$print_type] ?? 'طباعة') . ' — ' . htmlspecialchars($meeting['title']);
    ?></title>
    <style>
        @page {
            size: A4;
            margin: 15mm 20mm;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Segoe UI', 'Arial', 'Tahoma', sans-serif;
            font-size: 13pt;
            color: #222;
            line-height: 1.7;
            padding: 20px;
            max-width: 210mm;
            margin: 0 auto;
            background: #fff;
        }

        .print-page {
            page-break-after: always;
        }

        .print-page:last-child {
            page-break-after: auto;
        }

        /* Header */
        .doc-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 3px solid #1a237e;
            padding-bottom: 12px;
            margin-bottom: 20px;
        }

        .doc-header .company {
            font-size: 20pt;
            font-weight: bold;
            color: #1a237e;
        }

        .doc-header .company small {
            display: block;
            font-size: 10pt;
            color: #666;
            font-weight: normal;
        }

        .doc-header .doc-ref {
            text-align: left;
            font-size: 9pt;
            color: #555;
            border: 1px solid #ccc;
            padding: 8px 12px;
            border-radius: 6px;
        }

        /* Title */
        .doc-title {
            text-align: center;
            font-size: 18pt;
            font-weight: bold;
            color: #1a237e;
            margin: 25px 0 20px;
            padding: 12px;
            border: 2px solid #1a237e;
            border-radius: 8px;
            background: #f0f4ff;
        }

        /* Info Box */
        .info-box {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0;
            border: 1px solid #999;
            border-radius: 8px;
            overflow: hidden;
            margin-bottom: 20px;
        }

        .info-item {
            padding: 8px 15px;
            border-bottom: 1px solid #ddd;
            display: flex;
            gap: 10px;
        }

        .info-item:nth-child(odd) {
            border-left: 1px solid #ddd;
        }

        .info-label {
            font-weight: bold;
            color: #1a237e;
            min-width: 100px;
        }

        /* Tables */
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }

        th {
            background: #1a237e;
            color: #fff;
            padding: 10px;
            font-size: 11pt;
        }

        td {
            padding: 8px 10px;
            border: 1px solid #ccc;
            font-size: 11pt;
        }

        tbody tr:nth-child(even) {
            background: #f8f9fa;
        }

        /* Sections */
        .section-head {
            font-size: 14pt;
            font-weight: bold;
            color: #1a237e;
            margin: 20px 0 10px;
            padding-bottom: 6px;
            border-bottom: 2px solid #1a237e;
        }

        .agenda-item {
            padding: 8px 15px;
            margin-bottom: 5px;
            background: #f0f4ff;
            border-radius: 6px;
            border-right: 3px solid #1a237e;
            counter-increment: ai;
        }

        .agenda-item::before {
            content: counter(ai) ". ";
            font-weight: bold;
            color: #1a237e;
        }

        /* Signature */
        .sig-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin-top: 40px;
        }

        .sig-box {
            text-align: center;
        }

        .sig-box .sig-label {
            font-weight: bold;
            font-size: 10pt;
            color: #555;
            margin-bottom: 5px;
        }

        .sig-box .sig-line {
            border-bottom: 1px solid #333;
            height: 50px;
        }

        .footer-line {
            margin-top: 30px;
            border-top: 1px solid #ccc;
            padding-top: 8px;
            font-size: 9pt;
            color: #888;
            display: flex;
            justify-content: space-between;
        }

        /* Print */
        @media print {
            body {
                padding: 0;
            }

            .no-print {
                display: none !important;
            }
        }

        .no-print {
            text-align: center;
            margin-bottom: 20px;
        }

        .no-print button {
            padding: 10px 25px;
            background: #1a237e;
            color: #fff;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: bold;
            margin: 0 5px;
        }

        .no-print button:hover {
            opacity: .85;
        }

        .no-print a {
            color: #1a237e;
            text-decoration: none;
            margin: 0 10px;
        }
    </style>
</head>

<body>

    <div class="no-print">
        <button onclick="window.print()">🖨️ طباعة</button>
        <a href="meetings.php?id=<?= $meeting['id'] ?>">← العودة</a>
    </div>

    <?php if ($print_type === 'invite'): ?>
        <!-- ═══════════════ استدعاء اجتماع ═══════════════ -->
        <div class="print-page">
            <div class="doc-header">
                <div class="company">CANDY TEX<small>Société de Confection Textile</small></div>
                <div class="doc-ref">المرجع:
                    MTG-<?= date('Y', strtotime($meeting['meeting_date'])) ?>-<?= str_pad($meeting['id'], 3, '0', STR_PAD_LEFT) ?><br>التاريخ:
                    <?= date('d/m/Y') ?></div>
            </div>

            <div class="doc-title">📩 استدعاء لحضور اجتماع<br><small style="font-size:12pt;">Convocation à une
                    Réunion</small></div>

            <p style="font-size:14pt; margin-bottom:20px;">السلام عليكم ورحمة الله تعالى وبركاته،</p>
            <p style="margin-bottom:15px;">يشرفنا دعوتكم لحضور اجتماع
                <strong><?= htmlspecialchars($meeting['committee'] ?: $meeting['title']) ?></strong> وذلك حسب التفاصيل
                التالية:</p>

            <div class="info-box">
                <div class="info-item"><span class="info-label">📋
                        الموضوع:</span><span><?= htmlspecialchars($meeting['title']) ?></span></div>
                <div class="info-item"><span class="info-label">🏛️
                        اللجنة:</span><span><?= htmlspecialchars($meeting['committee'] ?: '—') ?></span></div>
                <div class="info-item"><span class="info-label">📅 التاريخ:</span><span><?= $day_ar ?>
                        <?= $date_fr ?></span></div>
                <div class="info-item"><span class="info-label">🕐 الساعة:</span><span><?= $time_fr ?></span></div>
                <div class="info-item"><span class="info-label">📍
                        المكان:</span><span><?= htmlspecialchars($meeting['location'] ?: '—') ?></span></div>
                <div class="info-item"><span class="info-label">📞
                        الداعي:</span><span><?= htmlspecialchars($meeting['called_by'] ?: '—') ?></span></div>
            </div>

            <?php if ($agenda): ?>
                <div class="section-head">📋 جدول الأعمال</div>
                <ol style="counter-reset:ai; list-style:none; padding:0;">
                    <?php foreach ($agenda as $item): ?>
                        <div class="agenda-item"><?= htmlspecialchars($item) ?></div><?php endforeach; ?>
                </ol>
            <?php endif; ?>

            <?php if ($attendees): ?>
                <div class="section-head">👥 قائمة المدعوين</div>
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>الاسم</th>
                            <th>الصفة</th>
                            <th>القسم</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($attendees as $i => $a): ?>
                            <tr>
                                <td style="text-align:center;"><?= $i + 1 ?></td>
                                <td><?= htmlspecialchars($a['name']) ?></td>
                                <td><?= htmlspecialchars($a['role_title'] ?: '—') ?></td>
                                <td><?= htmlspecialchars($a['department'] ?: '—') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <p style="margin-top:25px;">نرجو منكم الحضور في الموعد المحدد.</p>
            <p>وتقبلوا فائق التقدير والاحترام.</p>

            <div class="sig-grid">
                <div class="sig-box">
                    <div class="sig-label">الداعي للاجتماع</div>
                    <div class="sig-line"></div><small><?= htmlspecialchars($meeting['called_by'] ?: '') ?></small>
                </div>
                <div class="sig-box">
                    <div class="sig-label">المدير العام</div>
                    <div class="sig-line"></div>
                </div>
                <div class="sig-box">
                    <div class="sig-label">الختم</div>
                    <div class="sig-line"></div>
                </div>
            </div>

            <div class="footer-line"><span>CANDY TEX — إدارة الاجتماعات</span><span>صفحة 1/1</span></div>
        </div>

    <?php elseif ($print_type === 'agenda'): ?>
        <!-- ═══════════════ جدول الأعمال ═══════════════ -->
        <div class="print-page">
            <div class="doc-header">
                <div class="company">CANDY TEX<small>Société de Confection Textile</small></div>
                <div class="doc-ref">المرجع:
                    MTG-<?= date('Y', strtotime($meeting['meeting_date'])) ?>-<?= str_pad($meeting['id'], 3, '0', STR_PAD_LEFT) ?><br>التاريخ:
                    <?= $date_fr ?></div>
            </div>

            <div class="doc-title">📋 جدول الأعمال<br><small style="font-size:12pt;">Ordre du Jour</small></div>

            <div class="info-box">
                <div class="info-item"><span class="info-label">📋
                        الاجتماع:</span><span><?= htmlspecialchars($meeting['title']) ?></span></div>
                <div class="info-item"><span class="info-label">🏛️
                        اللجنة:</span><span><?= htmlspecialchars($meeting['committee'] ?: '—') ?></span></div>
                <div class="info-item"><span class="info-label">📅 التاريخ:</span><span><?= $day_ar ?>
                        <?= $date_fr ?></span></div>
                <div class="info-item"><span class="info-label">🕐 الساعة:</span><span><?= $time_fr ?></span></div>
            </div>

            <table>
                <thead>
                    <tr>
                        <th style="width:50px;">رقم</th>
                        <th>النقطة</th>
                        <th style="width:120px;">الملاحظات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($agenda): ?>
                        <?php foreach ($agenda as $i => $item): ?>
                            <tr>
                                <td style="text-align:center;font-weight:bold;"><?= $i + 1 ?></td>
                                <td><?= htmlspecialchars($item) ?></td>
                                <td></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    <tr>
                        <td style="text-align:center;font-weight:bold;"><?= count($agenda) + 1 ?></td>
                        <td>مواضيع متفرقة</td>
                        <td></td>
                    </tr>
                </tbody>
            </table>

            <?php if ($meeting['notes']): ?>
                <div class="section-head">📝 ملاحظات</div>
                <p style="background:#f8f9fa;padding:12px;border-radius:6px;"><?= nl2br(htmlspecialchars($meeting['notes'])) ?>
                </p>
            <?php endif; ?>

            <div class="footer-line"><span>CANDY TEX — جدول الأعمال</span><span><?= $date_fr ?></span></div>
        </div>

    <?php elseif ($print_type === 'minutes'): ?>
        <!-- ═══════════════ محضر الاجتماع ═══════════════ -->
        <div class="print-page">
            <div class="doc-header">
                <div class="company">CANDY TEX<small>Société de Confection Textile</small></div>
                <div class="doc-ref">المرجع:
                    PV-<?= date('Y', strtotime($meeting['meeting_date'])) ?>-<?= str_pad($meeting['id'], 3, '0', STR_PAD_LEFT) ?><br>التاريخ:
                    <?= $date_fr ?></div>
            </div>

            <div class="doc-title">📝 محضر اجتماع<br><small style="font-size:12pt;">Procès-Verbal de Réunion</small></div>

            <div class="info-box">
                <div class="info-item"><span class="info-label">📋
                        الموضوع:</span><span><?= htmlspecialchars($meeting['title']) ?></span></div>
                <div class="info-item"><span class="info-label">🏛️
                        اللجنة:</span><span><?= htmlspecialchars($meeting['committee'] ?: '—') ?></span></div>
                <div class="info-item"><span class="info-label">📅 التاريخ:</span><span><?= $day_ar ?>
                        <?= $date_fr ?></span></div>
                <div class="info-item"><span class="info-label">🕐 الساعة:</span><span><?= $time_fr ?></span></div>
                <div class="info-item"><span class="info-label">📍
                        المكان:</span><span><?= htmlspecialchars($meeting['location'] ?: '—') ?></span></div>
                <div class="info-item"><span class="info-label">📞 رئيس
                        الجلسة:</span><span><?= htmlspecialchars($meeting['called_by'] ?: '—') ?></span></div>
            </div>

            <!-- Attendees Present -->
            <div class="section-head">👥 الحاضرون</div>
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>الاسم</th>
                        <th>الصفة</th>
                        <th>القسم</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $present = array_filter($attendees, fn($a) => $a['attended'] === null || $a['attended']); ?>
                    <?php if ($present):
                        foreach (array_values($present) as $i => $a): ?>
                            <tr>
                                <td style="text-align:center;"><?= $i + 1 ?></td>
                                <td><?= htmlspecialchars($a['name']) ?></td>
                                <td><?= htmlspecialchars($a['role_title'] ?: '—') ?></td>
                                <td><?= htmlspecialchars($a['department'] ?: '—') ?></td>
                            </tr>
                        <?php endforeach; else: ?>
                        <tr>
                            <td colspan="4" style="text-align:center;color:#999;">لم يتم تسجيل الحضور بعد</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <?php $absent = array_filter($attendees, fn($a) => $a['attended'] === 0); ?>
            <?php if ($absent): ?>
                <div class="section-head">❌ الغائبون</div>
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>الاسم</th>
                            <th>الصفة</th>
                            <th>القسم</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_values($absent) as $i => $a): ?>
                            <tr>
                                <td style="text-align:center;"><?= $i + 1 ?></td>
                                <td><?= htmlspecialchars($a['name']) ?></td>
                                <td><?= htmlspecialchars($a['role_title'] ?: '—') ?></td>
                                <td><?= htmlspecialchars($a['department'] ?: '—') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <!-- Agenda discussed -->
            <?php if ($agenda): ?>
                <div class="section-head">📋 النقاط المتداولة</div>
                <ol style="counter-reset:ai; list-style:none; padding:0;">
                    <?php foreach ($agenda as $item): ?>
                        <div class="agenda-item"><?= htmlspecialchars($item) ?></div><?php endforeach; ?>
                </ol>
            <?php endif; ?>

            <!-- Decisions -->
            <?php if ($decisions): ?>
                <div class="section-head">📌 القرارات المتخذة</div>
                <table>
                    <thead>
                        <tr>
                            <th style="width:50px;">رقم</th>
                            <th>القرار</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($decisions as $i => $d): ?>
                            <tr>
                                <td style="text-align:center;font-weight:bold;"><?= $i + 1 ?></td>
                                <td><?= htmlspecialchars($d) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="section-head">📌 القرارات المتخذة</div>
                <div style="border:1px solid #ccc;border-radius:6px;padding:30px;text-align:center;color:#999;">يُملأ بعد
                    الاجتماع</div>
            <?php endif; ?>

            <?php if ($meeting['notes']): ?>
                <div class="section-head">📝 ملاحظات</div>
                <p style="background:#f8f9fa;padding:12px;border-radius:6px;"><?= nl2br(htmlspecialchars($meeting['notes'])) ?>
                </p>
            <?php endif; ?>

            <div class="sig-grid">
                <div class="sig-box">
                    <div class="sig-label">رئيس الجلسة</div>
                    <div class="sig-line"></div>
                </div>
                <div class="sig-box">
                    <div class="sig-label">المقرر</div>
                    <div class="sig-line"></div>
                </div>
                <div class="sig-box">
                    <div class="sig-label">المدير العام</div>
                    <div class="sig-line"></div>
                </div>
            </div>

            <div class="footer-line"><span>CANDY TEX — محضر اجتماع</span><span>صفحة 1/1</span></div>
        </div>

    <?php elseif ($print_type === 'attendance'): ?>
        <!-- ═══════════════ لائحة الحضور ═══════════════ -->
        <div class="print-page">
            <div class="doc-header">
                <div class="company">CANDY TEX<small>Société de Confection Textile</small></div>
                <div class="doc-ref">المرجع:
                    ATT-<?= date('Y', strtotime($meeting['meeting_date'])) ?>-<?= str_pad($meeting['id'], 3, '0', STR_PAD_LEFT) ?><br>التاريخ:
                    <?= $date_fr ?></div>
            </div>

            <div class="doc-title">👥 لائحة الحضور<br><small style="font-size:12pt;">Feuille de Présence</small></div>

            <div class="info-box">
                <div class="info-item"><span class="info-label">📋
                        الاجتماع:</span><span><?= htmlspecialchars($meeting['title']) ?></span></div>
                <div class="info-item"><span class="info-label">🏛️
                        اللجنة:</span><span><?= htmlspecialchars($meeting['committee'] ?: '—') ?></span></div>
                <div class="info-item"><span class="info-label">📅 التاريخ:</span><span><?= $day_ar ?>
                        <?= $date_fr ?></span></div>
                <div class="info-item"><span class="info-label">🕐 الساعة:</span><span><?= $time_fr ?></span></div>
                <div class="info-item"><span class="info-label">📍
                        المكان:</span><span><?= htmlspecialchars($meeting['location'] ?: '—') ?></span></div>
                <div class="info-item"><span class="info-label">📞 رئيس
                        الجلسة:</span><span><?= htmlspecialchars($meeting['called_by'] ?: '—') ?></span></div>
            </div>

            <table>
                <thead>
                    <tr>
                        <th style="width:40px;">#</th>
                        <th>الاسم الكامل</th>
                        <th>الصفة / المهمة</th>
                        <th>القسم</th>
                        <th style="width:70px;">الحضور</th>
                        <th style="width:120px;">التوقيع</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($attendees): ?>
                        <?php foreach ($attendees as $i => $a): ?>
                            <tr>
                                <td style="text-align:center;"><?= $i + 1 ?></td>
                                <td><?= htmlspecialchars($a['name']) ?></td>
                                <td><?= htmlspecialchars($a['role_title'] ?: '') ?></td>
                                <td><?= htmlspecialchars($a['department'] ?: '') ?></td>
                                <td style="text-align:center;"><?= $a['attended'] === null ? '☐' : ($a['attended'] ? '✅' : '❌') ?>
                                </td>
                                <td></td>
                            </tr>
                        <?php endforeach; ?>
                        <!-- Extra empty rows for walk-ins -->
                        <?php for ($j = count($attendees) + 1; $j <= count($attendees) + 4; $j++): ?>
                            <tr>
                                <td style="text-align:center;"><?= $j ?></td>
                                <td></td>
                                <td></td>
                                <td></td>
                                <td></td>
                                <td></td>
                            </tr>
                        <?php endfor; ?>
                    <?php else: ?>
                        <?php for ($j = 1; $j <= 10; $j++): ?>
                            <tr>
                                <td style="text-align:center;"><?= $j ?></td>
                                <td></td>
                                <td></td>
                                <td></td>
                                <td></td>
                                <td></td>
                            </tr>
                        <?php endfor; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <div class="sig-grid" style="grid-template-columns:1fr 1fr;">
                <div class="sig-box">
                    <div class="sig-label">رئيس الجلسة</div>
                    <div class="sig-line"></div>
                </div>
                <div class="sig-box">
                    <div class="sig-label">الختم والتوقيع</div>
                    <div class="sig-line"></div>
                </div>
            </div>

            <div class="footer-line"><span>CANDY TEX — لائحة الحضور</span><span><?= $date_fr ?></span></div>
        </div>
    <?php endif; ?>

</body>

</html>