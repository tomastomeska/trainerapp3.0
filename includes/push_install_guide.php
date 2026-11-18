<?php

function renderPushInstallGuide(): void
{
    ?>
    <details class="border rounded-3 mb-4" id="push-install-guide">
        <summary class="p-2 p-sm-3 fw-semibold" style="cursor:pointer">
            <i class="fas fa-circle-question text-warning me-2" aria-hidden="true"></i>Návod: aplikace na ploše a zapnutí oznámení
        </summary>
        <div class="p-2 p-sm-3 pt-0">
            <p>Vyberte své zařízení a postupujte podle návodu. Obrázky jsou <strong>ilustrační</strong>; nabídky se mohou podle verze systému a prohlížeče lišit.</p>

            <details class="border rounded-3 mb-2">
                <summary class="p-2 p-sm-3 fw-semibold" style="cursor:pointer"><i class="fab fa-apple me-2" aria-hidden="true"></i>iPhone a iPad</summary>
                <div class="px-2 px-sm-3 pb-3">
                    <p>Pro oznámení potřebujete iOS nebo iPadOS 16.4 či novější a TrainerApp přidanou na plochu. Oznámení potom zapínejte v aplikaci spuštěné z plochy, ne v běžné záložce Safari.</p>
                    <figure class="text-center bg-light rounded-3 p-2 mb-3">
                        <div class="row g-2 justify-content-center">
                        <div class="col-12 col-sm-6"><svg viewBox="10 12 245 166" role="img" aria-labelledby="push-guide-ios-share-title" style="width:100%;max-width:245px;height:auto">
                            <title id="push-guide-ios-share-title">Ilustrace: v Safari zvolte Sdílet.</title>
                            <rect x="10" y="12" width="245" height="166" rx="14" fill="#fff" stroke="#adb5bd" stroke-width="2"/>
                            <text x="132" y="40" text-anchor="middle" font-size="16" font-family="sans-serif" fill="#212529">1. Safari</text>
                            <rect x="42" y="53" width="180" height="48" rx="8" fill="#f1f3f5"/>
                            <text x="132" y="82" text-anchor="middle" font-size="15" font-family="sans-serif" fill="#495057">TrainerApp</text>
                            <rect x="117" y="125" width="30" height="26" rx="3" fill="none" stroke="#1463c6" stroke-width="3"/>
                            <path d="M132 139v-28m-8 8 8-8 8 8" fill="none" stroke="#1463c6" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
                            <text x="132" y="171" text-anchor="middle" font-size="14" font-family="sans-serif" fill="#1463c6">Sdílet</text>
                        </svg></div>
                        <div class="col-12 col-sm-6"><svg viewBox="285 12 245 166" role="img" aria-labelledby="push-guide-ios-add-title" style="width:100%;max-width:245px;height:auto">
                            <title id="push-guide-ios-add-title">Ilustrace: v nabídce sdílení zvolte Přidat na plochu.</title>
                            <rect x="285" y="12" width="245" height="166" rx="14" fill="#fff" stroke="#adb5bd" stroke-width="2"/>
                            <text x="407" y="40" text-anchor="middle" font-size="16" font-family="sans-serif" fill="#212529">2. Nabídka sdílení</text>
                            <rect x="302" y="65" width="211" height="55" rx="8" fill="#fff3cd" stroke="#e0a800"/>
                            <rect x="315" y="79" width="26" height="26" rx="4" fill="none" stroke="#212529" stroke-width="2"/>
                            <path d="M328 84v16m-8-8h16" stroke="#212529" stroke-width="2"/>
                            <text x="351" y="98" font-size="14" font-family="sans-serif" fill="#212529">Přidat na plochu</text>
                            <text x="407" y="151" text-anchor="middle" font-size="14" font-family="sans-serif" fill="#495057">Potvrďte Přidat</text>
                        </svg></div>
                        </div>
                        <figcaption class="small text-muted">Ilustrační postup v Safari</figcaption>
                    </figure>
                    <ol>
                        <li>Otevřete TrainerApp v <strong>Safari</strong> a přihlaste se ke svému účtu.</li>
                        <li>Klepněte na <strong>Sdílet</strong> (čtverec se šipkou nahoru). V nabídce sjeďte níže a vyberte <strong>Přidat na plochu</strong>. Pokud je nabídnuto „Otevřít jako webovou aplikaci“, nechte tuto možnost zapnutou. Potvrďte <strong>Přidat</strong>.</li>
                        <li>Vraťte se na plochu a spusťte TrainerApp její novou ikonou. Pokud je třeba, znovu se přihlaste ke správnému účtu.</li>
                        <li>Otevřete svůj profil a sekci <strong>Oznámení</strong>. Klepněte na <strong>Aktivovat na tomto zařízení</strong> a v systémovém dotazu zvolte <strong>Povolit</strong>.</li>
                        <li>Zapněte požadované kategorie a zkontrolujte stav a seznam zařízení podle společného postupu níže.</li>
                    </ol>
                    <p class="mb-0 small text-muted">Pokud jste povolení zamítli, zkontrolujte v iPhonu nebo iPadu <strong>Nastavení → Oznámení → TrainerApp</strong> a povolte oznámení. Položka se může objevit až po žádosti o povolení. Potom aplikaci otevřete z plochy a zkuste aktivaci znovu.</p>
                </div>
            </details>

            <details class="border rounded-3 mb-2">
                <summary class="p-2 p-sm-3 fw-semibold" style="cursor:pointer"><i class="fab fa-android me-2" aria-hidden="true"></i>Android</summary>
                <div class="px-2 px-sm-3 pb-3">
                    <p>Doporučujeme aktuální Google Chrome. Instalace na plochu je pohodlná, oznámení lze podle podpory zařízení zapnout i v prohlížeči.</p>
                    <figure class="text-center bg-light rounded-3 p-2 mb-3">
                        <div class="row g-2 justify-content-center">
                        <div class="col-12 col-sm-6"><svg viewBox="10 12 245 166" role="img" aria-labelledby="push-guide-android-menu-title" style="width:100%;max-width:245px;height:auto">
                            <title id="push-guide-android-menu-title">Ilustrace: v Chrome otevřete nabídku se třemi tečkami.</title>
                            <rect x="10" y="12" width="245" height="166" rx="14" fill="#fff" stroke="#adb5bd" stroke-width="2"/>
                            <text x="132" y="40" text-anchor="middle" font-size="16" font-family="sans-serif" fill="#212529">1. Chrome</text>
                            <rect x="27" y="56" width="206" height="38" rx="8" fill="#f1f3f5"/>
                            <text x="43" y="80" font-size="15" font-family="sans-serif" fill="#495057">TrainerApp</text>
                            <circle cx="216" cy="66" r="2.5" fill="#212529"/><circle cx="216" cy="75" r="2.5" fill="#212529"/><circle cx="216" cy="84" r="2.5" fill="#212529"/>
                            <text x="132" y="143" text-anchor="middle" font-size="14" font-family="sans-serif" fill="#495057">Otevřete nabídku ⋮</text>
                        </svg></div>
                        <div class="col-12 col-sm-6"><svg viewBox="285 12 245 166" role="img" aria-labelledby="push-guide-android-install-title" style="width:100%;max-width:245px;height:auto">
                            <title id="push-guide-android-install-title">Ilustrace: vyberte instalaci nebo přidání na plochu.</title>
                            <rect x="285" y="12" width="245" height="166" rx="14" fill="#fff" stroke="#adb5bd" stroke-width="2"/>
                            <text x="407" y="40" text-anchor="middle" font-size="16" font-family="sans-serif" fill="#212529">2. Nabídka</text>
                            <rect x="300" y="61" width="215" height="67" rx="8" fill="#fff3cd" stroke="#e0a800"/>
                            <text x="407" y="88" text-anchor="middle" font-size="14" font-family="sans-serif" fill="#212529">Nainstalovat aplikaci</text>
                            <text x="407" y="110" text-anchor="middle" font-size="14" font-family="sans-serif" fill="#212529">nebo Přidat na plochu</text>
                            <text x="407" y="153" text-anchor="middle" font-size="14" font-family="sans-serif" fill="#495057">Potvrďte instalaci</text>
                        </svg></div>
                        </div>
                        <figcaption class="small text-muted">Ilustrační postup v Chrome</figcaption>
                    </figure>
                    <ol>
                        <li>V <strong>Chrome</strong> otevřete TrainerApp a přihlaste se.</li>
                        <li>Klepněte na nabídku <strong>⋮</strong> a vyberte <strong>Nainstalovat aplikaci</strong> nebo <strong>Přidat na plochu</strong>. Pokud se zobrazí další nabídka, vyberte instalaci a potvrďte ji.</li>
                        <li>Otevřete TrainerApp z nové ikony. Podle potřeby se znovu přihlaste ke správnému účtu.</li>
                        <li>V profilu v sekci <strong>Oznámení</strong> klepněte na <strong>Aktivovat na tomto zařízení</strong>. V každém zobrazeném dotazu prohlížeče či systému zvolte <strong>Povolit</strong>.</li>
                        <li>Zapněte požadované kategorie a zkontrolujte stav a seznam zařízení podle společného postupu níže.</li>
                    </ol>
                    <p class="mb-0 small text-muted">Pokud jsou oznámení blokovaná, v Chrome otevřete informace o stránce u adresního řádku → <strong>Oprávnění / Nastavení webu → Oznámení</strong> a povolte je. Zkontrolujte také oznámení pro Chrome nebo TrainerApp v nastavení Androidu; názvy položek se liší podle telefonu.</p>
                </div>
            </details>

            <details class="border rounded-3 mb-3">
                <summary class="p-2 p-sm-3 fw-semibold" style="cursor:pointer"><i class="fas fa-desktop me-2" aria-hidden="true"></i>Počítač: Windows a Mac</summary>
                <div class="px-2 px-sm-3 pb-3">
                    <p>Na počítači doporučujeme aktuální Chrome nebo Microsoft Edge. Instalace aplikace je volitelná: oznámení lze zapnout i při používání webu v podporovaném prohlížeči.</p>
                    <figure class="text-center bg-light rounded-3 p-2 mb-3">
                        <svg viewBox="0 0 350 190" role="img" aria-labelledby="push-guide-pc-title" style="width:100%;max-width:350px;height:auto">
                            <title id="push-guide-pc-title">Ilustrace: v adresním řádku nebo nabídce prohlížeče zvolte instalaci aplikace a následně povolte oznámení.</title>
                            <rect x="10" y="12" width="330" height="145" rx="12" fill="#fff" stroke="#adb5bd" stroke-width="2"/>
                            <rect x="20" y="27" width="310" height="37" rx="7" fill="#f1f3f5"/>
                            <text x="30" y="51" font-size="16" font-family="sans-serif" fill="#495057">TrainerApp</text>
                            <rect x="181" y="33" width="112" height="25" rx="5" fill="#fff3cd" stroke="#e0a800"/>
                            <text x="237" y="50" text-anchor="middle" font-size="14" font-family="sans-serif" fill="#212529">Nainstalovat</text>
                            <text x="313" y="51" text-anchor="middle" font-size="22" font-family="sans-serif" fill="#212529">⋮</text>
                            <text x="175" y="96" text-anchor="middle" font-size="17" font-family="sans-serif" fill="#212529">Profil → Oznámení → Aktivovat</text>
                            <text x="175" y="127" text-anchor="middle" font-size="16" font-family="sans-serif" fill="#495057">V prohlížeči zvolte Povolit</text>
                            <path d="M130 158v12h90v-12m-110 12h130" fill="none" stroke="#adb5bd" stroke-width="3" stroke-linecap="round"/>
                        </svg>
                        <figcaption class="small text-muted">Ilustrační postup na počítači</figcaption>
                    </figure>
                    <ol>
                        <li>V <strong>Chrome nebo Edge</strong> otevřete TrainerApp a přihlaste se.</li>
                        <li>Pokud chcete samostatnou aplikaci, použijte ikonu instalace v adresním řádku nebo nabídku prohlížeče. V Chrome hledejte <strong>Nainstalovat aplikaci</strong>; v Edge položku <strong>Aplikace → Nainstalovat tento web jako aplikaci</strong>. Potvrďte instalaci. Dostupnost a názvy se mohou lišit.</li>
                        <li>Otevřete TrainerApp v prohlížeči nebo jako nainstalovanou aplikaci, přejděte do profilu a v sekci <strong>Oznámení</strong> klikněte na <strong>Aktivovat na tomto zařízení</strong>.</li>
                        <li>V dotazu prohlížeče zvolte <strong>Povolit</strong>. Zapněte požadované kategorie a zkontrolujte stav a seznam zařízení podle společného postupu níže.</li>
                    </ol>
                    <p class="mb-0 small text-muted">Při blokování zkontrolujte oprávnění webu u adresního řádku a také oznámení pro prohlížeč nebo aplikaci v nastavení Windows či macOS. Režim Nerušit nebo Soustředění může zobrazení oznámení omezit.</p>
                </div>
            </details>

            <h4 class="h6 fw-bold">Jak poznám, že je vše zapnuté?</h4>
            <ol>
                <li>V části <strong>Oznámení</strong> ověřte stav „Oznámení jsou pro toto zařízení registrována a povolena v prohlížeči“ a v seznamu hledejte označení <strong>toto zařízení</strong>.</li>
                <li>V části <strong>Kategorie oznámení</strong> zapněte požadované přepínače. Změny kategorií se ukládají při přepnutí; samotné zapnutí kategorií ale nenahradí aktivaci zařízení.</li>
                <li>Požádejte o vytvoření nové události, která vám má být oznámena, a ověřte doručení i otevření po klepnutí. Dřívější události se po aktivaci zpětně neodesílají. Doručení může trvat několik minut a závisí také na systému a připojení.</li>
            </ol>
            <h4 class="h6 fw-bold">Když oznámení nechodí</h4>
            <ul class="mb-0">
                <li>Ověřte správný přihlášený účet, povolení v systému i prohlížeči, aktivní zařízení a příslušnou kategorii. Na iPhonu či iPadu používejte ikonu přidanou na plochu.</li>
                <li>Každý telefon nebo počítač aktivujte zvlášť. Pokud přepínáte účty ve stejném prohlížeči, nová registrace může převzít odběr jinému účtu; přečtěte si případné upozornění.</li>
                <li>Pokud tlačítko aktivace není dostupné, počkejte na načtení stavu; při chybě obnovte stránku, ověřte připojení přes HTTPS a případně aktualizujte systém nebo prohlížeč.</li>
                <li>Vypněte režim Nerušit / Soustředění a ověřte připojení k internetu. Některé kategorie se teprve zapojují, takže samotný přepínač nezaručuje oznámení pro každou událost.</li>
            </ul>
        </div>
    </details>
    <?php
}
