---
layout: default
title: Contact
permalink: /contact/
---

<div class="contact-heading">
  <h1>お問い合わせ</h1>
  <p>人工芝施工・購入に関するご相談は、こちらのフォームよりお気軽にお寄せください。</p>
  <p>必要事項をご入力のうえ、送信をお願いいたします。</p>
  <p class="required-note">（*必須）</p>
</div>

<!-- Turnstile Script（必須）
     ※このURLはそのまま読み込む（プロキシ/キャッシュしない） -->
<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>

<form id="contactForm" novalidate>
  <div>
    <label>お名前 <span style="color:red;">*必須</span><br>
      <input name="name" type="text" required autocomplete="name">
    </label>
  </div>
  <br>

  <div>
    <label>フリガナ <span style="color:red;">*必須</span><br>
      <input name="furigana" type="text" required autocomplete="off" placeholder="例：シバフ タロウ">
    </label>
  </div>
  <br>

  <div>
    <label>連絡先メールアドレス <span style="color:red;">*必須</span><br>
      <input name="email" type="email" required autocomplete="email">
    </label>
  </div>
  <br>

  <div>
    <label>確認のためのメールアドレス <span style="color:red;">*必須</span><br>
      <input name="email_confirm" type="email" required autocomplete="email">
    </label>
  </div>
  <br>

  <div>
    <label>電話番号（任意）<br>
      <input name="phone" type="tel" autocomplete="tel" placeholder="例：090-1234-5678">
    </label>
  </div>
  <br>

  <div>
    <label>問い合わせ内容 <span style="color:red;">*必須</span><br>
      <select name="category" required>
        <option value="">選択してください</option>
        <option value="turf_construction_inquiry">人工芝施工のお問い合わせ</option>
        <option value="turf_purchase_inquiry">人工芝購入のお問い合わせ</option>
        <option value="other">その他</option>
      </select>
    </label>
  </div>
  <br>

  <div>
    <label>内容 <span style="color:red;">*必須</span><br>
      <textarea name="message" rows="7" required placeholder="お問い合わせ内容をご記入ください"></textarea>
    </label>
  </div>
  <br>

  <!-- スパム対策：ハニーポット（人は触らない想定） -->
  <div style="display:none;">
    <label>Website
      <input name="website" type="text" tabindex="-1" autocomplete="off">
    </label>
  </div>

  <!-- 既存スパム対策：GAS側ワンタイムトークン（JSで取得） -->
  <input type="hidden" name="token" id="tokenField" value="">
  <input type="hidden" name="startedAt" id="startedAtField" value="">

  <!-- Turnstile Widget（Implicit rendering） -->
  <div class="cf-turnstile" data-sitekey="0x4AAAAAACXZKzxGEevYcIf2"></div>

  <button type="submit" id="submitBtn">送信</button>
  <p id="status" style="margin-top:1rem;"></p>
</form>

<script>
  // ====== 設定 ======
  const ENDPOINT = "https://script.google.com/macros/s/AKfycbyLMiBdx8mhFKavAdvrotnnnDj0YhSVLpi7SMLURXNXPJhRsps-2FbPF0L3hagV_2Rz/exec";
  const MIN_FILL_MS = 3000; // 3秒

  // ====== 要素 ======
  const form = document.getElementById("contactForm");
  const statusEl = document.getElementById("status");
  const submitBtn = document.getElementById("submitBtn");
  const tokenField = document.getElementById("tokenField");
  const startedAtField = document.getElementById("startedAtField");

  function setStatus(msg, isError = false) {
    statusEl.textContent = msg;
    statusEl.style.color = isError ? "crimson" : "inherit";
  }

  // 送信開始時刻（ページ表示時点）※2回目以降も使えるように let
  let startedAt = Date.now();
  startedAtField.value = String(startedAt);

  // 既存スパム対策：GASトークン取得
  // silent=true のときは成功時にステータス文言を上書きしない（＝①対策）
  async function initToken(silent = false) {
    try {
      if (!silent) {
        setStatus("フォーム準備中…");
      }
      submitBtn.disabled = true;

      const res = await fetch(`${ENDPOINT}?action=token`, {
        method: "GET",
        redirect: "follow",
      });
      const data = await res.json();

      if (data && data.ok && data.token) {
        tokenField.value = data.token;

        // ✅ silent のときは表示を変えない
        if (!silent) {
          setStatus("入力後、送信してください。");
        }
        submitBtn.disabled = false;
      } else {
        // ここは silent でも失敗を知らせたほうがよい（次回送信できないため）
        setStatus("初期化に失敗しました。ページを再読み込みしてください。", true);
        console.warn("token init failed:", data);
      }
    } catch (e) {
      console.error(e);
      setStatus("初期化に失敗しました。ネットワークをご確認の上、再読み込みしてください。", true);
    }
  }

  // 初回トークン取得
  initToken(false);

  form.addEventListener("submit", async (ev) => {
    ev.preventDefault();

    // 送信までが早すぎる場合は弾く
    const elapsed = Date.now() - startedAt;
    if (elapsed < MIN_FILL_MS) {
      setStatus("入力時間が短すぎます。少し待ってから送信してください。", true);
      return;
    }

    setStatus("送信中…");
    submitBtn.disabled = true;

    const fd = new FormData(form);

    // ハニーポット（触られていたら終了）
    if ((fd.get("website") || "").trim() !== "") {
      setStatus("送信に失敗しました。", true);
      submitBtn.disabled = false;
      return;
    }

    // Turnstile token（フォームに自動で入る hidden input を拾う）
    const tsInput = form.querySelector('input[name="cf-turnstile-response"]');
    const turnstileToken = tsInput ? (tsInput.value || "").trim() : "";
    if (!turnstileToken) {
      setStatus("認証が完了していません。しばらく待ってから再度お試しください。", true);
      submitBtn.disabled = false;
      return;
    }

    // 必須値取得
    const name = (fd.get("name") || "").trim();
    const furigana = (fd.get("furigana") || "").trim();
    const email = (fd.get("email") || "").trim();
    const emailConfirm = (fd.get("email_confirm") || "").trim();
    const phone = (fd.get("phone") || "").trim();
    const category = (fd.get("category") || "").trim();
    const message = (fd.get("message") || "").trim();
    const token = (fd.get("token") || "").trim();
    const startedAtSend = Number(fd.get("startedAt") || 0);

    // 必須チェック
    if (!name || !furigana || !email || !emailConfirm || !category || !message) {
      setStatus("必須項目が未入力です。赤*の項目をご確認ください。", true);
      submitBtn.disabled = false;
      return;
    }

    // メール一致チェック
    if (email.toLowerCase() !== emailConfirm.toLowerCase()) {
      setStatus("メールアドレス（確認用）が一致しません。", true);
      submitBtn.disabled = false;
      return;
    }

    // 自前トークン必須
    if (!token) {
      setStatus("初期化が完了していません。ページを再読み込みしてください。", true);
      submitBtn.disabled = false;
      return;
    }

    // payload（GAS doPostへ送る）
    const payload = {
      name,
      furigana,
      email,
      phone,
      category,
      message,

      // 既存スパム対策
      website: (fd.get("website") || "").trim(),
      token,
      startedAt: startedAtSend,

      // Turnstile
      turnstileToken
    };

    try {
      const res = await fetch(ENDPOINT, {
        method: "POST",
        redirect: "follow",
        headers: { "Content-Type": "text/plain;charset=utf-8" },
        body: JSON.stringify(payload),
      });

      const data = await res.json();

      if (data.ok) {
        // ✅ 成功メッセージを表示
        setStatus("送信できました。ありがとうございます！");
        form.reset();

        // resetすると hidden も消えるので再セット
        startedAt = Date.now();
        startedAtField.value = String(startedAt);
        tokenField.value = "";

        // ✅ Turnstileも次回用にリセット（任意だけど安定）
        if (window.turnstile) {
          try { window.turnstile.reset(); } catch (_) {}
        }

        // ✅ ①：成功表示を上書きせず、次回送信のためにトークンだけ裏で再取得
        await initToken(true);

        // 送信ボタンは initToken 内で必要に応じて制御される
        return;
      }

      const err = (data.error || "").toString();
      if (err === "too_fast") {
        setStatus("入力時間が短すぎます。少し待ってから送信してください。", true);
      } else if (err === "rate_limited") {
        setStatus("送信間隔が短すぎます。少し時間をおいて再送してください。", true);
      } else if (err === "token_invalid" || err === "no_token") {
        setStatus("ページの有効期限が切れました。再読み込みしてから送信してください。", true);
      } else if (err === "no_captcha") {
        setStatus("認証が未完了です。認証後に送信してください。", true);
      } else if (err === "captcha_failed") {
        setStatus("認証に失敗しました。時間を置いて再度お試しください。", true);
      } else if (err === "spam") {
        setStatus("送信に失敗しました。", true);
      } else {
        setStatus("送信に失敗しました：" + (data.error || "unknown"), true);
      }

      // エラー時は自前トークン再取得（使い捨てのため）
      tokenField.value = "";
      await initToken(false);

    } catch (e) {
      console.error(e);
      setStatus("通信エラー：" + e, true);
    } finally {
      // initToken側でも制御するが、ここで解除しておく（UI保険）
      submitBtn.disabled = false;
    }
  });
</script>