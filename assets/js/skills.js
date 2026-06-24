(function(){
    'use strict';
    function init(){
        document.querySelectorAll('.aiwp-delete-skill').forEach(function(btn){
            btn.addEventListener('click',function(){
                if(!confirm('Удалить скилл?'))return;
                var d=new FormData();d.append('action','aiwp_delete_skill');d.append('nonce',AIWP.nonce);d.append('slug',btn.dataset.slug);
                fetch(AIWP.ajax_url,{method:'POST',body:d}).then(function(r){return r.json();}).then(function(r){if(r.success)location.reload();else alert(r.data?.message||'Error');});
            });
        });
        document.querySelectorAll('.aiwp-export-skill').forEach(function(btn){
            btn.addEventListener('click',function(){
                var d=new FormData();d.append('action','aiwp_chat');d.append('nonce',AIWP.nonce);d.append('message','Экспортируй скилл '+btn.dataset.slug+' в JSON');
                fetch(AIWP.ajax_url,{method:'POST',body:d}).then(function(r){return r.json();}).then(function(r){if(r.success)alert(r.data.message);});
            });
        });
    }
    document.addEventListener('DOMContentLoaded',init);
})();
