# Saved image-analysis batches

In Admin Media Library, select images and choose **Analyze selected**, or choose **Analyze missing images** to find sources without an existing analysis. **Open saved batch** restores progress. In public `/images`, choose **Select for analysis** to select private images, or **Batch analysis** to open saved batches and analyze missing images. Analysis selection is independent of the four-image limit for canvas image inputs.

The browser advances one image at a time while the batch panel is open and visible. Each item has a durable analysis request ID and a source fingerprint. Pause saves the stop request immediately; an image already being analyzed can finish and save its result. Closing the panel stops further steps. Reopening loads progress and requires an explicit resume. This does not require a cron job or a separate worker service.

Draft descriptions, visible text, objects, scene, style, colors, tags and uncertainties can be inspected and edited individually. **Approve selected drafts** saves the exact analysis version shown; conflicts remain available for review. Only reviewed metadata enters the searchable image inventory. Batch analysis leaves photo-profile suggestions off; those remain an explicit per-image choice.

Usage is settled by the existing image-analysis service against the account that starts the batch. Admin usage remains metered under unlimited Admin rights. Reopening progress and reviewing metadata make no new model call. An uncertain provider outcome is recovered by reading saved state, without automatically resending the paid request.

The authenticated private endpoint is `api/image-analysis-batch.php`; the Admin endpoint is `admin/api/image-analysis-batch.php`. GET lists saved batches, or reads one using `id`. CSRF-protected POST actions are `create`, `step`, `pause`, `resume`, `cancel` and `review`. The host chooses account and scope from the authenticated session. Private images and analysis stay within their account. Batch records are included in account export and removed on account closure.
