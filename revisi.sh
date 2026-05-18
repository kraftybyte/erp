# Fix chart options di blade
python3 << 'EOF'
path = 'resources/views/filament/resources/hr-report/pages/view-hr-report.blade.php'
content = open(path).read()

# Fix 1: tinggi chart sama
content = content.replace(
    'style="position:relative;height:190px;"',
    'style="position:relative;height:220px;"'
)

# Fix 2: attendance y-axis — jangan mulai dari 0, mulai dari 60 biar proporsional
old = "                scales:{\n                    x:{ grid:{display:false}, ticks:{font:{size:10}} },\n                    y:{ beginAtZero:true, max:100, grid:{color:'#f1f5f9'}, ticks:{ font:{size:10}, callback:function(v){ return v+'%'; } } }\n                }"
new = "                scales:{\n                    x:{ grid:{display:false}, ticks:{font:{size:10}} },\n                    y:{ min:60, max:100, grid:{color:'#f1f5f9'}, ticks:{ font:{size:10}, callback:function(v){ return v+'%'; } } }\n                }"
content = content.replace(old, new)

open(path, 'w').write(content)
print("✅ Done")
EOF