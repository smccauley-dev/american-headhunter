import { forwardRef } from 'react'
import { FilePond, registerPlugin } from 'react-filepond'
import 'filepond/dist/filepond.min.css'
import FilePondPluginImagePreview from 'filepond-plugin-image-preview'
import 'filepond-plugin-image-preview/dist/filepond-plugin-image-preview.min.css'
import FilePondPluginFileValidateType from 'filepond-plugin-file-validate-type'
import FilePondPluginFileValidateSize from 'filepond-plugin-file-validate-size'
import FilePondPluginImageExifOrientation from 'filepond-plugin-image-exif-orientation'

/**
 * One FilePond, everywhere. This is the single source of truth for every
 * file-upload surface in the member/customer front-end, so they all read exactly
 * like the admin Filament FileUpload (which is itself FilePond): the same dashed
 * parchment dropzone, the same "Drag & Drop … Browse" label, image previews, and
 * square corners per theme.md. The squared/parchment skin lives globally in
 * app.css rooted at `.filepond--root`, so it applies to every instance rendered
 * through this component automatically.
 *
 * Plugins are registered once, here, for the whole app.
 *
 * Three usage modes, selected purely by which props the parent passes:
 *
 *  • Staged (temp/commit) — pass `processUrl` + `revertUrl` pointing at a temp
 *    endpoint. Each dropped file instant-uploads to a token; the parent reads the
 *    tokens off `ref.current.getFiles().map(f => f.serverId)` and commits them on
 *    Submit. Used by the property Photos and Map tabs (mirrors the admin modal).
 *
 *  • Direct (instant) — pass `processUrl` (no revert) at a real endpoint that
 *    returns the new record id as plain text. The parent reloads its data in
 *    `onprocessfiles`. Used by the profile Photos gallery and avatar.
 *
 *  • Local (form field) — pass neither url. FilePond just holds the File; the
 *    parent reads `ref.current.getFiles()[0]?.file` (or via `onupdatefiles`) and
 *    submits it with the surrounding form. Used by the Apply / Lease document
 *    fields.
 */
registerPlugin(
  FilePondPluginImagePreview,
  FilePondPluginFileValidateType,
  FilePondPluginFileValidateSize,
  FilePondPluginImageExifOrientation,
)

/** Laravel's encrypted CSRF cookie — FilePond runs its own XHR, so (unlike
 * Inertia) it must set the X-XSRF-TOKEN header itself. */
function xsrfToken(): string {
  const m = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/)
  return m ? decodeURIComponent(m[1]) : ''
}

const DEFAULT_LABEL =
  'Drag &amp; Drop your files or <span class="filepond--label-action">Browse</span>'

export interface FilePondUploaderProps {
  /** Field name FilePond posts the file under (default `file`). */
  name?: string
  allowMultiple?: boolean
  maxFiles?: number
  /** e.g. "10MB" — enables the size-validation plugin. */
  maxFileSize?: string
  acceptedFileTypes?: string[]
  /** Set false to suppress FilePond's built-in image-preview canvas (e.g. when the
   * parent renders its own thumbnails). Defaults to true. */
  allowImagePreview?: boolean
  labelIdle?: string
  /** Staged/direct mode: POST target for each file. Omit for local (form) mode. */
  processUrl?: string
  /** Staged mode: DELETE target to revert a staged file. */
  revertUrl?: string
  /** Compact single-file layout (e.g. avatar). */
  stylePanelLayout?: string
  imagePreviewHeight?: number
  onupdatefiles?: (files: any[]) => void
  onprocessfile?: (error: any, file: any) => void
  /** Fires once all queued files finish processing (direct mode reload hook). */
  onprocessfiles?: () => void
}

/** Ref proxies the FilePond instance methods (getFiles, removeFiles, …). */
const FilePondUploader = forwardRef<any, FilePondUploaderProps>(function FilePondUploader(props, ref) {
  const headers = { 'X-XSRF-TOKEN': xsrfToken() }
  const server: Record<string, unknown> = {}
  if (props.processUrl) {
    server.process = { url: props.processUrl, method: 'POST', withCredentials: true, headers }
  }
  if (props.revertUrl) {
    server.revert = { url: props.revertUrl, method: 'DELETE', withCredentials: true, headers }
  }

  return (
    <FilePond
      ref={ref}
      name={props.name ?? 'file'}
      credits={false}
      allowMultiple={props.allowMultiple ?? false}
      maxFiles={props.maxFiles}
      maxFileSize={props.maxFileSize}
      acceptedFileTypes={props.acceptedFileTypes}
      allowImagePreview={props.allowImagePreview ?? true}
      labelIdle={props.labelIdle ?? DEFAULT_LABEL}
      stylePanelLayout={props.stylePanelLayout}
      imagePreviewHeight={props.imagePreviewHeight}
      allowRevert={!!props.revertUrl}
      instantUpload={!!props.processUrl}
      server={props.processUrl ? server : undefined}
      onupdatefiles={props.onupdatefiles}
      onprocessfile={props.onprocessfile}
      onprocessfiles={props.onprocessfiles}
    />
  )
})

export default FilePondUploader
