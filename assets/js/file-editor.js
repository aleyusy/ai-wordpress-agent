(function(){
    'use strict';
    var currentFile='';

    function init(){
        loadFiles();
    }

    function loadFiles(){
        var tree=document.getElementById('aiwp-file-tree');
        if(!tree)return;
        var d=new FormData();d.append('action','aiwp_list_theme_files');d.append('nonce',AIWP.nonce);
        fetch(AIWP.ajax_url,{method:'POST',body:d}).then(function(r){return r.json();}).then(function(r){
            if(!r.success||!r.data.files){tree.innerHTML='<p>Ошибка загрузки</p>';return;}
            tree.innerHTML='';
            r.data.files.forEach(function(f){
                var div=document.createElement('div');
                div.className='aiwp-file-tree-item'+(f.type==='directory'?' type-dir':'')+(f.path.indexOf('/')>-1?' aiwp-file-tree-indent':'');
                div.textContent=(f.type==='directory'?'📁 ':'📄 ')+f.name;
                if(f.type==='file'){
                    div.addEventListener('click',function(){openFile(f.path,f.name);});
                }
                tree.appendChild(div);
            });
        }).catch(function(){tree.innerHTML='<p>Ошибка сети</p>';});
    }

    function openFile(path,name){
        currentFile=path;
        document.getElementById('aiwp-file-name').textContent=path;
        var d=new FormData();d.append('action','aiwp_read_theme_file');d.append('nonce',AIWP.nonce);d.append('file_path',path);
        fetch(AIWP.ajax_url,{method:'POST',body:d}).then(function(r){return r.json();}).then(function(r){
            var editor=document.getElementById('aiwp-file-editor');
            var saveBtn=document.getElementById('aiwp-save-file');
            if(r.success){editor.value=r.data.content;editor.disabled=false;saveBtn.disabled=false;}
            else{alert(r.data?.error||'Error');}
        });
    }

    function saveFile(){
        if(!currentFile)return;
        var content=document.getElementById('aiwp-file-editor').value;
        var d=new FormData();d.append('action','aiwp_write_theme_file');d.append('nonce',AIWP.nonce);d.append('file_path',currentFile);d.append('content',content);
        fetch(AIWP.ajax_url,{method:'POST',body:d}).then(function(r){return r.json();}).then(function(r){
            if(r.success)alert('Файл сохранён!');else alert(r.data?.error||'Ошибка');
        }).catch(function(){alert('Ошибка сети');});
    }

    document.addEventListener('DOMContentLoaded',function(){
        init();
        var saveBtn=document.getElementById('aiwp-save-file');
        if(saveBtn)saveBtn.addEventListener('click',function(){if(confirm('Сохранить файл?'))saveFile();});
    });
})();
