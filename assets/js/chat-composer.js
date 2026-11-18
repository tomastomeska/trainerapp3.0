(function () {
    const emojis = ['😀', '😃', '😄', '😊', '😉', '😍', '🤔', '👍', '🙏', '🎉'];
    const fileAccept = '.pdf,.doc,.docx,.xls,.xlsx,.csv,.jpg,.jpeg,.png,.gif,.webp,.heic,.heif,.zip,.rar,.7z,.mp4,.mov,.avi,.mkv,.webm,.txt,.ppt,.pptx';

    document.querySelectorAll('[data-chat-composer]').forEach(function (form) {
        if (form.dataset.chatComposerInitialized === 'true') return;
        const input = form.querySelector('[data-chat-input]');
        const sendButton = form.querySelector('[data-chat-send]');
        if (!input || !sendButton) return;

        form.dataset.chatComposerInitialized = 'true';
        form.enctype = 'multipart/form-data';
        input.required = false;
        if (!sendButton.hasAttribute('aria-label')) {
            sendButton.setAttribute('aria-label', 'Odeslat zprávu');
        }

        const controls = document.createElement('div');
        controls.className = 'chat-composer-controls';
        controls.innerHTML = '<button type="button" class="chat-composer-button" data-emoji-toggle aria-label="Vložit smajlík" title="Smajlíky">😊</button>'
            + '<button type="button" class="chat-composer-button chat-composer-plus" data-attachment-toggle aria-label="Přidat přílohu" title="Přidat přílohu">+</button>';

        const fileInput = document.createElement('input');
        fileInput.type = 'file';
        fileInput.name = 'attachment';
        fileInput.accept = fileAccept;
        fileInput.hidden = true;

        const cameraInput = document.createElement('input');
        cameraInput.type = 'file';
        cameraInput.name = 'camera_attachment';
        cameraInput.accept = 'image/*';
        cameraInput.setAttribute('capture', 'environment');
        cameraInput.hidden = true;

        const picker = document.createElement('div');
        picker.className = 'chat-emoji-picker';
        picker.hidden = true;
        picker.setAttribute('aria-label', 'Výběr smajlíků');
        picker.innerHTML = emojis.map(function (emoji) {
            return '<button type="button" data-emoji="' + emoji + '" aria-label="Vložit ' + emoji + '">' + emoji + '</button>';
        }).join('');

        const attachmentMenu = document.createElement('div');
        attachmentMenu.className = 'chat-attachment-menu';
        attachmentMenu.hidden = true;
        attachmentMenu.innerHTML = '<button type="button" data-pick-file><i class="fas fa-folder-open"></i> Vybrat soubor</button>'
            + '<button type="button" data-pick-camera><i class="fas fa-camera"></i> Vyfotit</button>';

        const attachmentPreview = document.createElement('div');
        attachmentPreview.className = 'chat-attachment-preview';
        attachmentPreview.hidden = true;
        attachmentPreview.setAttribute('role', 'status');
        attachmentPreview.setAttribute('aria-live', 'polite');

        const attachmentIcon = document.createElement('i');
        attachmentIcon.className = 'fas chat-attachment-icon fa-paperclip';
        attachmentIcon.setAttribute('aria-hidden', 'true');
        const attachmentKind = document.createElement('span');
        attachmentKind.className = 'chat-attachment-kind';
        const attachmentName = document.createElement('span');
        attachmentName.className = 'chat-attachment-name';
        const removeAttachment = document.createElement('button');
        removeAttachment.type = 'button';
        removeAttachment.className = 'chat-attachment-remove';
        removeAttachment.setAttribute('aria-label', 'Odebrat přílohu');
        removeAttachment.innerHTML = '<i class="fas fa-times" aria-hidden="true"></i>';
        attachmentPreview.append(attachmentIcon, attachmentKind, attachmentName, removeAttachment);

        const toolbar = document.createElement('div');
        toolbar.className = 'chat-composer-toolbar';
        sendButton.parentNode.insertBefore(toolbar, sendButton);
        toolbar.append(controls, sendButton);

        const wrapper = document.createElement('div');
        wrapper.className = 'chat-composer-wrap';
        input.parentNode.insertBefore(wrapper, input);
        wrapper.append(input, picker, attachmentMenu);
        form.insertBefore(attachmentPreview, wrapper);
        form.append(fileInput, cameraInput);

        controls.querySelector('[data-emoji-toggle]').addEventListener('click', function () {
            picker.hidden = !picker.hidden;
            attachmentMenu.hidden = true;
        });
        controls.querySelector('[data-attachment-toggle]').addEventListener('click', function () {
            attachmentMenu.hidden = !attachmentMenu.hidden;
            picker.hidden = true;
        });
        attachmentMenu.querySelector('[data-pick-file]').addEventListener('click', function () {
            fileInput.click();
            attachmentMenu.hidden = true;
        });
        attachmentMenu.querySelector('[data-pick-camera]').addEventListener('click', function () {
            cameraInput.click();
            attachmentMenu.hidden = true;
        });
        picker.addEventListener('click', function (event) {
            const button = event.target.closest('[data-emoji]');
            if (!button) return;
            const start = input.selectionStart === null ? input.value.length : input.selectionStart;
            const end = input.selectionEnd === null ? start : input.selectionEnd;
            input.setRangeText(button.dataset.emoji, start, end, 'end');
            input.focus();
        });

        function setFileName(file) {
            const name = file ? Array.from(file.name) : [];
            const isImage = file && file.type.indexOf('image/') === 0;
            const isVideo = file && file.type.indexOf('video/') === 0;
            attachmentIcon.className = 'fas chat-attachment-icon ' + (isImage ? 'fa-image' : (isVideo ? 'fa-video' : 'fa-paperclip'));
            attachmentKind.textContent = file ? (isImage ? 'Obrázek připraven' : (isVideo ? 'Video připraveno' : 'Soubor připraven')) : '';
            attachmentName.textContent = name.length > 6 ? name.slice(0, 5).join('') + '…' : name.join('');
            attachmentPreview.hidden = !file;
        }
        removeAttachment.addEventListener('click', function () {
            fileInput.value = '';
            cameraInput.value = '';
            setFileName(null);
        });
        form.addEventListener('reset', function () {
            setFileName(null);
        });
        fileInput.addEventListener('change', function () {
            if (fileInput.files.length) cameraInput.value = '';
            setFileName(fileInput.files[0] || cameraInput.files[0] || null);
        });
        cameraInput.addEventListener('change', function () {
            if (cameraInput.files.length) fileInput.value = '';
            setFileName(cameraInput.files[0] || fileInput.files[0] || null);
        });

        form.addEventListener('submit', function (event) {
            const hasFile = Boolean(fileInput.files.length || cameraInput.files.length);
            if (!input.value.trim() && !hasFile) {
                event.preventDefault();
                input.focus();
                return;
            }

            if (event.defaultPrevented && !sendButton.disabled) return;
            sendButton.setAttribute('aria-busy', 'true');
            if (!event.defaultPrevented) sendButton.disabled = true;
        });
    });

    function initializeChatVideoPreview(preview) {
        if (preview.dataset.videoPreviewInitialized) return;
        const video = preview.querySelector('video');
        const poster = preview.querySelector('.admin-chat-video-poster');
        const playButton = preview.querySelector('.admin-chat-video-play');
        if (!video || !poster || !playButton) return;

        preview.dataset.videoPreviewInitialized = 'true';
        let posterSeekPending = false;
        let returnPlaceholder = null;
        const closeButton = document.createElement('button');
        closeButton.type = 'button';
        closeButton.className = 'admin-chat-video-close';
        closeButton.setAttribute('aria-label', 'Zavřít video');
        closeButton.innerHTML = '<i class="fas fa-times" aria-hidden="true"></i>';
        preview.appendChild(closeButton);

        function closePreview() {
            if (!preview.classList.contains('is-fullscreen')) return;
            video.pause();
            video.controls = false;
            document.removeEventListener('keydown', handlePreviewKeydown);
            document.body.classList.remove('chat-video-overlay-open');

            if (returnPlaceholder && returnPlaceholder.parentNode) {
                returnPlaceholder.parentNode.insertBefore(preview, returnPlaceholder);
                returnPlaceholder.remove();
            } else {
                preview.remove();
            }
            returnPlaceholder = null;
            preview.classList.remove('is-fullscreen', 'is-playing');
            playButton.focus();
        }

        function handlePreviewKeydown(event) {
            if (event.key === 'Escape') closePreview();
        }

        closeButton.addEventListener('click', closePreview);
        preview.addEventListener('click', function (event) {
            if (event.target === preview) closePreview();
        });

        function capturePoster() {
            if (poster.dataset.ready || !video.videoWidth || !video.videoHeight) return;
            try {
                const canvas = document.createElement('canvas');
                const scale = Math.min(1, 640 / Math.max(video.videoWidth, video.videoHeight));
                canvas.width = Math.max(1, Math.round(video.videoWidth * scale));
                canvas.height = Math.max(1, Math.round(video.videoHeight * scale));
                canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
                poster.src = canvas.toDataURL('image/jpeg', 0.78);
                poster.dataset.ready = 'true';

                const availableWidth = Math.min(preview.parentElement.clientWidth || 320, 320);
                const displayScale = Math.min(availableWidth / video.videoWidth, 260 / video.videoHeight);
                preview.style.width = Math.round(video.videoWidth * displayScale) + 'px';
                preview.style.height = Math.round(video.videoHeight * displayScale) + 'px';
                preview.classList.add('has-poster');
            } catch (error) {
                console.error('Náhled videa v chatu se nepodařilo vytvořit:', error);
            }
        }

        video.addEventListener('loadedmetadata', function () {
            if (Number.isFinite(video.duration) && video.duration > 0) {
                posterSeekPending = true;
                video.addEventListener('seeked', function () {
                    posterSeekPending = false;
                    capturePoster();
                }, { once: true });
                try {
                    video.currentTime = Math.min(0.5, video.duration / 2);
                } catch (error) {
                    posterSeekPending = false;
                    console.error('Snímek videa v chatu se nepodařilo načíst:', error);
                }
            }
        }, { once: true });
        video.addEventListener('loadeddata', function () {
            if (!posterSeekPending) capturePoster();
        }, { once: true });
        video.addEventListener('error', function () {
            console.error('Video v chatu se nepodařilo načíst:', video.currentSrc);
        }, { once: true });
        playButton.addEventListener('click', function () {
            if (returnPlaceholder || !preview.parentNode) return;
            returnPlaceholder = document.createElement('span');
            returnPlaceholder.hidden = true;
            preview.parentNode.insertBefore(returnPlaceholder, preview);
            document.body.appendChild(preview);
            preview.classList.add('is-fullscreen', 'is-playing');
            document.body.classList.add('chat-video-overlay-open');
            document.addEventListener('keydown', handlePreviewKeydown);
            closeButton.focus();
            video.controls = true;
            if (video.readyState > 0) video.currentTime = 0;
            const playback = video.play();
            if (playback && typeof playback.catch === 'function') {
                playback.catch(function (error) {
                    closePreview();
                    console.error('Video v chatu se nepodařilo přehrát:', error);
                });
            }
        });
        video.load();
    }

    function initializeChatVideoPreviews(root) {
        if (root.matches && root.matches('[data-chat-video-preview]')) {
            initializeChatVideoPreview(root);
        }
        root.querySelectorAll('[data-chat-video-preview]').forEach(initializeChatVideoPreview);
    }

    initializeChatVideoPreviews(document);
    if (!window.trainerAppChatVideoPreviewObserver) {
        window.trainerAppChatVideoPreviewObserver = new MutationObserver(function (mutations) {
            mutations.forEach(function (mutation) {
                mutation.addedNodes.forEach(function (node) {
                    if (node.nodeType === Node.ELEMENT_NODE) initializeChatVideoPreviews(node);
                });
            });
        });
        window.trainerAppChatVideoPreviewObserver.observe(document.body, { childList: true, subtree: true });
    }

    document.querySelectorAll('[data-chat-delete-api]').forEach(function (container) {
        if (container.dataset.chatDeleteInitialized === 'true') return;
        container.dataset.chatDeleteInitialized = 'true';
        container.addEventListener('click', async function (event) {
            const target = event.target instanceof Element ? event.target : null;
            if (!target) return;
            const toggle = target.closest('[data-chat-message-menu-toggle]');
            if (toggle && container.contains(toggle)) {
                const menu = toggle.parentElement.querySelector('[data-chat-message-menu]');
                container.querySelectorAll('[data-chat-message-menu]').forEach(function (item) {
                    if (item !== menu) item.hidden = true;
                });
                menu.hidden = !menu.hidden;
                return;
            }

            const button = target.closest('[data-chat-delete-scope]');
            if (!button || !container.contains(button)) {
                if (!target.closest('[data-chat-message-menu]')) {
                    container.querySelectorAll('[data-chat-message-menu]').forEach(function (item) { item.hidden = true; });
                }
                return;
            }
            const row = button.closest('[data-message-id]');
            const scope = button.dataset.chatDeleteScope;
            if (!row) return;
            if (scope === 'everyone' && !window.confirm('Opravdu chcete odstranit tuto zprávu pro všechny účastníky?')) return;

            const formData = new FormData();
            formData.append('message_id', row.dataset.messageId);
            formData.append('scope', scope);
            formData.append('csrf_token', container.dataset.chatDeleteCsrf);
            try {
                const response = await fetch(container.dataset.chatDeleteApi, {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin'
                });
                const data = await response.json();
                if (!response.ok || !data || !data.ok) {
                    throw new Error(data && data.error ? data.error : 'Zprávu se nepodařilo odstranit.');
                }
                if (data.warning) window.alert(data.warning);
                if (scope === 'me') row.remove();
                else window.location.reload();
            } catch (error) {
                window.alert(error && error.message ? error.message : 'Zprávu se nepodařilo odstranit.');
            }
        });
    });
})();
