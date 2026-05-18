document.addEventListener("DOMContentLoaded",async()=>{if("serviceWorker"in navigator)try{const e=await navigator.serviceWorker.register("/sw.js");console.log("[PWA] Service Worker registered:",e.scope),e.addEventListener("updatefound",()=>{const o=e.installing;o.addEventListener("statechange",()=>{o.state==="installed"&&navigator.serviceWorker.controller&&l()})})}catch(e){console.error("[PWA] Service Worker registration failed:",e)}await a(),await d()});async function a(){if(!("Notification"in window)){console.log("[PWA] Browser does not support notifications");return}if(Notification.permission==="granted"){console.log("[PWA] Notification permission already granted");return}Notification.permission!=="denied"&&await Notification.requestPermission()==="granted"&&(console.log("[PWA] Notification permission granted"),f())}async function d(){var e;if(!("serviceWorker"in navigator)||!("PushManager"in window)){console.log("[PWA] Push notifications not supported");return}try{const o=await navigator.serviceWorker.ready,n=await fetch("/api/push/vapid-public-key");if(!n.ok){console.log("[PWA] Push notifications not configured");return}const{publicKey:t}=await n.json(),i=await o.pushManager.subscribe({userVisibleOnly:!0,applicationServerKey:p(t)});await fetch("/api/push/subscribe",{method:"POST",headers:{"Content-Type":"application/json","X-CSRF-TOKEN":(e=document.querySelector('meta[name="csrf-token"]'))==null?void 0:e.content},body:JSON.stringify(i)}),console.log("[PWA] Subscribed to push notifications")}catch(o){console.error("[PWA] Failed to subscribe to push:",o)}}function p(e){const o="=".repeat((4-e.length%4)%4),n=(e+o).replace(/-/g,"+").replace(/_/g,"/"),t=window.atob(n),i=new Uint8Array(t.length);for(let r=0;r<t.length;++r)i[r]=t.charCodeAt(r);return i}function l(){const e=document.createElement("div");e.innerHTML=`
        <div style="
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: linear-gradient(135deg, #6366f1, #8b5cf6);
            color: white;
            padding: 16px 24px;
            border-radius: 12px;
            box-shadow: 0 8px 32px rgba(99, 102, 241, 0.4);
            z-index: 99999;
            display: flex;
            align-items: center;
            gap: 12px;
            animation: slideIn 0.3s ease-out;
        ">
            <span style="font-size: 24px;">🔄</span>
            <div>
                <strong style="font-size: 14px;">Update Available</strong>
                <p style="font-size: 12px; opacity: 0.9; margin: 4px 0 0;">New version ready. Click to reload.</p>
            </div>
            <button onclick="location.reload()" style="
                background: white;
                color: #6366f1;
                border: none;
                padding: 8px 16px;
                border-radius: 8px;
                font-weight: 600;
                cursor: pointer;
            ">Reload</button>
        </div>
    `,document.body.appendChild(e),setTimeout(()=>e.remove(),1e4)}function f(){const e=document.createElement("div");e.innerHTML=`
        <div style="
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: #10b981;
            color: white;
            padding: 16px 24px;
            border-radius: 12px;
            box-shadow: 0 8px 32px rgba(16, 185, 129, 0.4);
            z-index: 99999;
            display: flex;
            align-items: center;
            gap: 12px;
            animation: slideIn 0.3s ease-out;
        ">
            <span style="font-size: 24px;">🔔</span>
            <div>
                <strong style="font-size: 14px;">Notifications Enabled</strong>
                <p style="font-size: 12px; opacity: 0.9; margin: 4px 0 0;">You'll receive updates from ERP Krafty</p>
            </div>
        </div>
    `,document.body.appendChild(e),setTimeout(()=>e.remove(),5e3)}const c=document.createElement("style");c.textContent=`
    @keyframes slideIn {
        from {
            transform: translateX(100%);
            opacity: 0;
        }
        to {
            transform: translateX(0);
            opacity: 1;
        }
    }
`;document.head.appendChild(c);window.addEventListener("online",()=>{console.log("[PWA] Back online"),s("🌐 Koneksi kembali","Anda sedang online","success")});window.addEventListener("offline",()=>{console.log("[PWA] Gone offline"),s("📡 Sedang offline","Beberapa fitur mungkin terbatas","warning")});function s(e,o,n="info"){const t={success:"#10b981",warning:"#f59e0b",error:"#ef4444",info:"#6366f1"},i=document.createElement("div");i.innerHTML=`
        <div style="
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: ${t[n]||t.info};
            color: white;
            padding: 16px 24px;
            border-radius: 12px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.2);
            z-index: 99999;
            display: flex;
            align-items: center;
            gap: 12px;
            animation: slideIn 0.3s ease-out;
        ">
            <strong style="font-size: 14px;">${e}</strong>
            <p style="font-size: 12px; opacity: 0.9; margin: 0;">${o}</p>
        </div>
    `,document.body.appendChild(i),setTimeout(()=>i.remove(),5e3)}window.PWA={subscribeToPushNotifications:d,requestNotificationPermission:a,showToast:s};
