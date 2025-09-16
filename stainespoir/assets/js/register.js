'use strict';

// --- Guard + initialisation idempotente (Turbo/UX, bfcache, etc.) ---
function initRegisterPage() {
    const form = document.getElementById('registerForm');
    if (!form) return;                    // pas la bonne page
    if (form.dataset.inited === '1') return; // déjà initialisé
    form.dataset.inited = '1';

    // --------- Ton code, ajusté sans DOMContentLoaded wrapper ---------
    const panes = [
        document.querySelector('.step-pane-1'),
        document.querySelector('.step-pane-2'),
        document.querySelector('.step-pane-3'),
        document.querySelector('.step-pane-4'),
    ];
    const bullets  = Array.from(document.querySelectorAll('.wizard li'));
    const msg      = document.getElementById('formMsg');
    const kidsList = document.getElementById('kidsList');

    // Endpoint & CSRF (indépendant de Twig inline)
    const endpoint =
        form.dataset.endpoint ||
        window.REGISTER_ENDPOINT ||
        document.querySelector('meta[name="register-endpoint"]')?.content ||
        '';
    const csrfToken =
        document.getElementById('csrfField')?.value ||
        document.querySelector('meta[name="csrf-token"]')?.content ||
        '';

    // Boutons
    const btnNext   = document.getElementById('btnNext');
    const btnNext2  = document.getElementById('btnNext2');
    const btnNext3  = document.getElementById('btnNext3');
    const btnPrev   = document.getElementById('btnPrev');
    const btnPrev2  = document.getElementById('btnPrev2');
    const btnPrev3  = document.getElementById('btnPrev3');
    const btnAddKid = document.getElementById('btnAddKid');

    let step = 0;

    function show(el){ if(!el) return; el.removeAttribute('hidden'); el.classList?.remove('hidden'); }
    function hide(el){ if(!el) return; el.setAttribute('hidden','true'); el.classList?.add('hidden'); }

    function showStep(i){
        step = Math.max(0, Math.min(3, i));
        panes.forEach((p, idx) => p?.classList.toggle('hidden', idx !== step));
        bullets.forEach((li, idx) => {
            li.classList.toggle('active', idx <= step);
            li.classList.toggle('current', idx === step);
        });
        if (msg) msg.textContent = '';
        if (step === 3) buildRecap();
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    function fieldVal(id){ const el = document.getElementById(id); return el ? (el.value || '').trim() : ''; }
    function scorePwd(v){ let s=0; if(!v) return 0; if(v.length>=8) s++; if(/[A-Z]/.test(v)) s++; if(/[0-9]/.test(v)) s++; if(/[^A-Za-z0-9]/.test(v)) s++; return s; }

    // Jauge + show/hide password (pour les deux champs)
    const pass  = document.getElementById('su_password');
    const meter = pass ? pass.closest('.password')?.querySelector('.meter i') : null;
    pass?.addEventListener('input', e => { const sc = scorePwd(e.target.value); if (meter) meter.style.setProperty('--p', sc/4); });
    document.querySelectorAll('.showpass').forEach(btn => btn.addEventListener('click', (ev) => {
        ev.preventDefault();
        const inp = btn.parentElement?.querySelector('input');
        if (!inp) return;
        inp.type = (inp.type === 'password') ? 'text' : 'password';
    }));

    function validate(currentOnly = true){
        const errors = {};
        const err = (k,v)=>errors[k]=v;

        const check1 = () => {
            const email = fieldVal('su_email');
            const p = fieldVal('su_password');
            const c = fieldVal('su_confirm');
            if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) err('account.email','Email invalide.');
            if (scorePwd(p) < 3) err('account.password','Mot de passe trop faible (8+, 1 maj, 1 chiffre, 1 symbole).');
            if (p !== c) err('account.confirm','Les mots de passe ne correspondent pas.');
        };
        const check2 = () => {
            if (!fieldVal('su_parent_prenom')) err('parent.firstName','Prénom requis.');
            if (!fieldVal('su_parent_nom')) err('parent.lastName','Nom requis.');
            if (!document.getElementById('su_lien')?.value) err('parent.relation','Lien avec l’enfant requis.');
        };
        const check3 = () => {
            const blocks = kidsList ? kidsList.querySelectorAll('.kid') : [];
            if (!blocks || blocks.length < 1) { err('kids','Ajoutez au moins un enfant.'); return; }
            blocks.forEach((kid, i) => {
                const v = s => kid.querySelector(`[name$="${s}"]`)?.value?.trim() || '';
                if (!v('_prenom')) err(`kids.${i}.firstName`, 'Prénom requis.');
                if (!v('_nom')) err(`kids.${i}.lastName`, 'Nom requis.');
                if (!v('_niveau')) err(`kids.${i}.level`, 'Niveau requis.');
            });
        };
        const check4 = () => {
            if (!document.getElementById('consent_rgpd')?.checked) err('consents.rgpd','Consentement RGPD requis.');
        };

        const checks = [check1, check2, check3, check4];
        if (currentOnly) checks[step](); else checks.forEach(fn => fn());

        if (Object.keys(errors).length) {
            const first = errors[Object.keys(errors)[0]] || 'Veuillez corriger les erreurs.';
            if (msg) msg.textContent = first;
            return false;
        }
        if (msg) msg.textContent = '';
        return true;
    }

    // Kids
    function addKid(prefill = {}){
        if(!kidsList) return;
        const id = 'kid_' + Math.random().toString(36).slice(2,8);
        const el = document.createElement('div');
        el.className = 'kid card';
        el.innerHTML = `
      <div class="grid-3">
        <label class="fgroup floating"><input required type="text" name="${id}_prenom" value="${prefill.firstName||''}" placeholder=" "><span>Prénom</span></label>
        <label class="fgroup floating"><input required type="text" name="${id}_nom" value="${prefill.lastName||''}" placeholder=" "><span>Nom</span></label>
        <label class="fgroup floating"><input type="date" name="${id}_dob" value="${prefill.dob||''}" placeholder=" "><span>Date de naissance</span></label>
        <label class="fgroup floating">
          <select required name="${id}_niveau">
            <option value="" disabled ${!prefill.level?'selected':''}>Choisir…</option>
            <option>CE2</option><option>CM1</option><option>CM2</option>
            <option>6e</option><option>5e</option><option>4e</option><option>3e</option>
            <option>2nde</option><option>1ère</option><option>Terminale</option>
          </select>
          <span>Niveau</span>
        </label>
        <label class="fgroup floating"><input name="${id}_etab" value="${prefill.school||''}" placeholder=" "><span>Établissement (optionnel)</span></label>
        <label class="fgroup floating"><textarea name="${id}_notes" rows="2" placeholder=" ">${prefill.notes||''}</textarea><span>Allergies / particularités (optionnel)</span></label>

        <label style="display:flex;align-items:center;gap:8px;margin:0">
          <input type="checkbox" name="${id}_alone" ${prefill.canLeaveAlone ? 'checked' : ''}>
          <span>Autoriser <b>le retour seul</b> pour cet enfant</span>
        </label>
      </div>

      <div class="row" style="gap:10px;align-items:center;margin-top:8px">
        <div class="kid-actions" style="margin-left:auto">
          <button type="button" class="btn danger remove">Supprimer</button>
        </div>
      </div>
    `;
        el.querySelector('.remove')?.addEventListener('click', () => el.remove());
        kidsList.appendChild(el);
    }

    btnAddKid?.addEventListener('click', () => addKid());
    if (kidsList && !kidsList.children.length) addKid();

    // Récap (lit aussi le checkbox "retour seul")
    function buildRecap(){
        const email = fieldVal('su_email');
        const nom   = fieldVal('su_parent_nom');
        const prenom= fieldVal('su_parent_prenom');
        const tel   = fieldVal('su_parent_tel');
        const lien  = document.getElementById('su_lien')?.value || '';
        const adr   = fieldVal('su_adresse');
        const cp    = fieldVal('su_cp');
        const ville = fieldVal('su_ville');

        const kids = [];
        kidsList?.querySelectorAll('.kid').forEach(kid => {
            const v = s => kid.querySelector(`[name$="${s}"]`)?.value?.trim() || '';
            const alone = !!kid.querySelector(`[name$="_alone"]`)?.checked;
            kids.push({
                prenom: v('_prenom'),
                nom:    v('_nom'),
                dob:    v('_dob'),
                niveau: v('_niveau'),
                etab:   v('_etab'),
                notes:  v('_notes'),
                canLeaveAlone: alone,
            });
        });

        const rec = document.getElementById('recap'); if(!rec) return;
        rec.innerHTML = `
      <div class="recap-card">
        <h4>Compte</h4>
        <div><b>Email</b><br>${email}</div>
      </div>
      <div class="recap-card">
        <h4>Parent</h4>
        <div><b>${prenom} ${nom}</b></div>
        <div>${lien || ''}</div>
        <div>${tel || ''}</div>
        <div>${[adr, cp, ville].filter(Boolean).join(' ')}</div>
      </div>
      <div class="recap-card">
        <h4>Enfant(s)</h4>
        ${kids.map(k => `
          <div class="kid-mini">
            <b>${k.prenom} ${k.nom}</b> — ${k.niveau || '-'}${k.dob ? ` · ${k.dob}` : ''}
            ${k.etab ? `<div class="muted">${k.etab}</div>` : ''}
            ${k.notes ? `<div class="muted">${k.notes}</div>` : ''}
            <div class="muted">Retour seul : ${k.canLeaveAlone ? 'Oui' : 'Non'}</div>
          </div>
        `).join('')}
      </div>
    `;
    }

    // Navigation
    btnNext ?.addEventListener('click', () => { if (!validate(true)) return; showStep(1); });
    btnNext2?.addEventListener('click', () => { if (!validate(true)) return; showStep(2); });
    btnNext3?.addEventListener('click', () => { if (!validate(true)) return; showStep(3); });
    btnPrev ?.addEventListener('click', () => showStep(0));
    btnPrev2?.addEventListener('click', () => showStep(1));
    btnPrev3?.addEventListener('click', () => showStep(2));

    // Submit
    form.addEventListener('submit', async (e) => {
        e.preventDefault();

        // Valide toutes les étapes (en les affichant successivement)
        const ok1 = (showStep(0), validate(true));
        const ok2 = (showStep(1), validate(true));
        const ok3 = (showStep(2), validate(true));
        const ok4 = (showStep(3), validate(true));
        if (!(ok1 && ok2 && ok3 && ok4)) { return; }

        const kidsPayload = [];
        document.querySelectorAll('#kidsList .kid').forEach(kid => {
            const v = (s) => kid.querySelector(`[name$="${s}"]`)?.value?.trim() || '';
            kidsPayload.push({
                firstName: v('_prenom'),
                lastName:  v('_nom'),
                dob:       v('_dob'),
                level:     v('_niveau'),
                school:    v('_etab') || null,
                notes:     v('_notes') || null,
                canLeaveAlone: !!kid.querySelector(`[name$="_alone"]`)?.checked,
            });
        });

        const payload = {
            _token: csrfToken,
            account: {
                email:    fieldVal('su_email'),
                password: fieldVal('su_password'),
                confirm:  fieldVal('su_confirm'),
            },
            parent: {
                firstName: fieldVal('su_parent_prenom'),
                lastName:  fieldVal('su_parent_nom'),
                phone:     fieldVal('su_parent_tel'),
                relation:  document.getElementById('su_lien')?.value || '',
                address:   fieldVal('su_adresse') || null,
                postalCode:fieldVal('su_cp') || null,
                city:      fieldVal('su_ville') || null,
            },
            kids: kidsPayload,
            consents: {
                rgpd:  !!document.getElementById('consent_rgpd')?.checked,
                photo: !!document.getElementById('consent_photo')?.checked,
            }
        };

        if (!endpoint) {
            console.error('Register endpoint manquant. Ajoute data-endpoint sur #registerForm ou la meta <meta name="register-endpoint">.');
            if (msg) msg.textContent = 'Configuration invalide (endpoint manquant).';
            return;
        }

        try{
            const res = await fetch(endpoint, {
                method:'POST',
                headers:{ 'Content-Type':'application/json', 'X-Requested-With':'XMLHttpRequest' },
                body: JSON.stringify(payload),
                credentials: 'same-origin'
            });

            const successBlock = document.getElementById('successBlock');
            const formSection  = document.getElementById('formSection');

            if (res.ok){
                show(successBlock);
                hide(formSection);
                const lead = document.querySelector('.auth-hero .lead');
                if (lead) lead.textContent = 'Votre compte a été créé avec succès.';
                window.scrollTo({ top: 0, behavior: 'smooth' });
            } else {
                const data = await res.json().catch(()=>({}));
                const firstError = data?.errors ? (Object.values(data.errors)[0] || 'Erreur de validation.') : (data?.message || 'Une erreur est survenue.');
                if (msg) msg.textContent = firstError;
                window.scrollTo({ top: 0, behavior: 'smooth' });
            }
        } catch(e){
            if (msg) msg.textContent = 'Impossible de joindre le serveur.';
        }
    });

    // Init
    showStep(0);
}

// --- Hook pour tous les cas de navigation ---
document.addEventListener('turbo:load',   initRegisterPage);
document.addEventListener('turbo:render', initRegisterPage);
document.addEventListener('DOMContentLoaded', initRegisterPage);
window.addEventListener('pageshow', (e) => { if (e.persisted) initRegisterPage(); });
