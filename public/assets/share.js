(function () {
  'use strict';

  var card = document.querySelector('[data-share-card]');
  if (!card) {
    return;
  }

  var tools = card.querySelector('[data-share-tools]');
  var copyButton = card.querySelector('[data-share-copy]');
  var copyLabel = card.querySelector('[data-share-label]');
  var copyIcon = copyButton ? copyButton.querySelector('.icon use') : null;
  var copyIconHref = copyIcon ? copyIcon.getAttribute('href') : '';
  var nativeButton = card.querySelector('[data-share-native]');
  var status = card.querySelector('[data-share-status]');
  var url = card.getAttribute('data-share-url') || window.location.href;
  var title = card.getAttribute('data-share-title') || document.title;
  var description = card.getAttribute('data-share-description') || '';
  var resetTimer = 0;

  if (!tools || !copyButton) {
    return;
  }

  tools.hidden = false;

  function setStatus(message) {
    if (status) {
      status.textContent = message;
    }
  }

  function setCopyIcon(name) {
    if (!copyIcon || !copyIconHref) {
      return;
    }
    copyIcon.setAttribute('href', copyIconHref.replace(/#i-(link|check)$/, '#i-' + name));
  }

  function setCopied() {
    window.clearTimeout(resetTimer);
    copyButton.classList.add('is-copied');
    if (copyLabel) {
      copyLabel.textContent = '复制成功';
    }
    setCopyIcon('check');
    setStatus('');
    resetTimer = window.setTimeout(function () {
      copyButton.classList.remove('is-copied');
      if (copyLabel) {
        copyLabel.textContent = '复制链接';
      }
      setCopyIcon('link');
      setStatus('');
    }, 2600);
  }

  function legacyCopy(value) {
    var area = document.createElement('textarea');
    area.value = value;
    area.setAttribute('readonly', '');
    area.style.position = 'fixed';
    area.style.left = '-9999px';
    area.style.top = '0';
    document.body.appendChild(area);
    area.select();
    var copied = false;
    try {
      copied = document.execCommand('copy');
    } catch (error) {
      copied = false;
    }
    document.body.removeChild(area);

    return copied;
  }

  function copy(value) {
    if (navigator.clipboard && window.isSecureContext) {
      return navigator.clipboard.writeText(value).then(function () {
        return true;
      }).catch(function () {
        return legacyCopy(value);
      });
    }

    return Promise.resolve(legacyCopy(value));
  }

  copyButton.addEventListener('click', function () {
    copy(url).then(function (copied) {
      if (copied) {
        setCopied();
      } else {
        setStatus('复制失败，请从浏览器地址栏复制。');
      }
    });
  });

  if (nativeButton && typeof navigator.share === 'function') {
    nativeButton.hidden = false;
    nativeButton.addEventListener('click', function () {
      navigator.share({
        title: title,
        text: description,
        url: url
      }).then(function () {
        setStatus('已打开系统分享。');
      }).catch(function (error) {
        if (error && error.name === 'AbortError') {
          return;
        }
        copy(url).then(function (copied) {
          setStatus(copied ? '已复制链接，去粘贴分享吧。' : '分享失败，请从浏览器地址栏复制。');
        });
      });
    });
  }
}());
