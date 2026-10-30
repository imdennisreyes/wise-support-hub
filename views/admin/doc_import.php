<div class="page-heading">
    <p class="eyebrow">Admin · Import</p>
    <h1>Document Import</h1>
    <p>Upload a Word or PDF document to automatically create a Draft article in the knowledge base.</p>
</div>

<?php require base_path('views/admin/_nav.php'); ?>

<?php if ($errors): ?>
<div class="alert alert-danger" role="alert">
    <strong><i class="bi bi-exclamation-circle me-1"></i>Import failed</strong>
    <ul class="mb-0 mt-1">
        <?php foreach ($errors as $error): ?>
            <li><?= e($error) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<div class="row g-4">

    <div class="col-lg-7">
        <section class="panel">
            <h2>Upload document</h2>
            <p class="text-secondary mb-4">The document title becomes the article title. The remaining content is placed in the description field. You can edit everything before publishing.</p>

            <form method="post" enctype="multipart/form-data" data-loading-form>
                <?= csrf_field() ?>

                <div class="mb-3">
                    <label class="form-label fw-semibold" for="document">File <span class="text-danger">*</span></label>
                    <input class="form-control form-control-lg"
                           type="file"
                           id="document"
                           name="document"
                           accept=".docx,.pdf,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/pdf"
                           required>
                    <div class="form-text">Word (.docx) or PDF · max 16 MB</div>
                </div>

                <div class="mb-4">
                    <label class="form-label fw-semibold" for="type">Article type</label>
                    <select class="form-select" id="type" name="type">
                        <option value="Article">Article</option>
                        <option value="SOP">SOP</option>
                        <option value="Error">Error guide</option>
                        <option value="Request">Request</option>
                    </select>
                    <div class="form-text">You can change this after import on the article edit page.</div>
                </div>

                <button class="btn btn-primary btn-lg" type="submit" data-loading-text="Extracting content…">
                    <i class="bi bi-file-earmark-arrow-up me-1"></i>
                    <span>Import document</span>
                </button>
            </form>
        </section>
    </div>

    <div class="col-lg-5">
        <section class="panel">
            <h2>How it works</h2>
            <ol class="mb-0" style="padding-left:1.25rem;line-height:2">
                <li>Upload your Word or PDF file</li>
                <li>Text is extracted automatically</li>
                <li>A <strong>Draft</strong> article is created</li>
                <li>You are taken to the edit page to review</li>
                <li>Adjust the content, then <strong>Publish</strong></li>
                <li>ItemGPT can search the article immediately</li>
            </ol>
        </section>

        <section class="panel mt-4">
            <h2>Tips for best results</h2>
            <ul class="mb-0 text-secondary" style="padding-left:1.25rem;line-height:2">
                <li>Make the document's <strong>first line</strong> the article title</li>
                <li>Use heading styles in Word for clean paragraphs</li>
                <li>PDFs must be text-based, not scanned images</li>
                <li>Images in documents are <strong>not</strong> imported — add them after via the editor</li>
                <li>Max file size: 16 MB</li>
            </ul>
        </section>
    </div>

</div>
