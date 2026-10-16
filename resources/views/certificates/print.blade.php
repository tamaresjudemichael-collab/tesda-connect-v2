<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Training Completion Certificate — {{ $certificate->certificate_number }}</title>
    <style>
        :root { color-scheme: light; }
        * { box-sizing: border-box; }
        body { margin: 0; padding: 24px; background: #eef2f7; color: #172033; font-family: Georgia, 'Times New Roman', serif; }
        .toolbar { max-width: 1100px; margin: 0 auto 16px; display: flex; justify-content: flex-end; gap: 12px; font-family: Arial, sans-serif; }
        .toolbar button { border: 0; border-radius: 8px; background: #12366b; color: white; padding: 12px 18px; cursor: pointer; font-weight: 700; }
        .certificate { max-width: 1100px; min-height: 720px; margin: 0 auto; border: 10px double #12366b; background: white; padding: 58px 64px; text-align: center; position: relative; }
        .brand { font-family: Arial, sans-serif; font-weight: 800; letter-spacing: .18em; color: #12366b; text-transform: uppercase; font-size: 13px; }
        h1 { color: #12366b; text-transform: uppercase; letter-spacing: .06em; font-size: 36px; margin: 28px 0 12px; }
        .subtitle { font-family: Arial, sans-serif; font-size: 14px; color: #64748b; }
        .learner-name { font-size: 35px; font-weight: 700; border-bottom: 1px solid #cbd5e1; display: inline-block; padding: 8px 28px 12px; margin: 20px 0; }
        .body-copy { max-width: 720px; margin: 0 auto; font-size: 18px; line-height: 1.8; }
        .course { font-weight: 700; color: #12366b; font-size: 22px; }
        .meta { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin: 42px auto 0; max-width: 740px; font-family: Arial, sans-serif; text-align: left; }
        .meta div { border-top: 1px solid #cbd5e1; padding-top: 10px; font-size: 13px; }
        .meta strong { display: block; color: #12366b; text-transform: uppercase; letter-spacing: .06em; font-size: 10px; margin-bottom: 6px; }
        .completed { display: inline-block; margin-top: 30px; padding: 9px 24px; border: 2px solid #047857; color: #047857; font-family: Arial, sans-serif; font-size: 14px; font-weight: 800; letter-spacing: .18em; }
        .footer { margin-top: 42px; font-family: Arial, sans-serif; font-size: 11px; color: #64748b; }
        @@media print { @@page { size: A4 landscape; margin: 8mm; } body { background: white; padding: 0; } .toolbar { display: none !important; } .certificate { max-width: none; min-height: 180mm; margin: 0; padding: 18mm 22mm; break-inside: avoid; } }
        @@media (max-width: 700px) { body { padding: 10px; } .certificate { padding: 30px 20px; min-height: 0; } h1 { font-size: 26px; } .learner-name { font-size: 25px; } .meta { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <div class="toolbar">
        <button type="button" onclick="window.print()">Print / Save as PDF</button>
    </div>

    <main class="certificate">
        <div class="brand">TESDA Connect · Training Operations</div>
        <h1>Certificate of Training Completion</h1>
        <p class="subtitle">This certificate is issued to</p>
        <div class="learner-name">{{ $application->learner_name }}</div>
        <div class="body-copy">
            for completing the training program
            <div class="course">{{ $application->course?->name ?? 'Training Course' }}</div>
            conducted by <strong>{{ $application->institution?->name ?? 'Training Institution' }}</strong>.
        </div>

        <div class="completed">STATUS: COMPLETED</div>

        <div class="meta">
            <div><strong>Certificate Number</strong>{{ $certificate->certificate_number }}</div>
            <div><strong>Certificate Issued</strong>{{ $certificate->issued_at?->format('F d, Y') }}</div>
            <div><strong>Training Start Date</strong>{{ $application->trainingBatch?->starts_at?->format('F d, Y') }}</div>
            <div><strong>Training End Date</strong>{{ $application->trainingBatch?->ends_at?->format('F d, Y') }}</div>
        </div>
        <div class="footer">This certificate records completion status only. It does not contain grades, scores, or numerical ratings.</div>
    </main>
</body>
</html>
