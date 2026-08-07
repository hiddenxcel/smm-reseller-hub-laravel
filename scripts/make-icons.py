"""Generate the favicon and app-icon set from the source logo."""

from PIL import Image

SRC = '/root/code/smm-reseller-hub/storage/app/logo-source.png'
OUT = '/root/code/smm-reseller-hub/public'

src = Image.open(SRC).convert('RGBA')
print('source', src.size)

# The mark is a rounded square that already carries its own green field, so it
# is used as-is rather than padded onto another background.
for size, name in [
    (16, 'favicon-16x16.png'),
    (32, 'favicon-32x32.png'),
    (48, 'favicon-48x48.png'),
    (180, 'apple-touch-icon.png'),
    (192, 'icon-192.png'),
    (512, 'icon-512.png'),
]:
    src.resize((size, size), Image.LANCZOS).save(f'{OUT}/{name}', 'PNG', optimize=True)
    print('wrote', name)

# Multi-resolution .ico so Windows/legacy browsers pick the size they want.
src.resize((256, 256), Image.LANCZOS).save(
    f'{OUT}/favicon.ico',
    format='ICO',
    sizes=[(16, 16), (32, 32), (48, 48), (64, 64), (128, 128), (256, 256)],
)
print('wrote favicon.ico')

# A full-size copy for anywhere the logo is shown large (og image, emails).
src.resize((512, 512), Image.LANCZOS).save(f'{OUT}/logo.png', 'PNG', optimize=True)
print('wrote logo.png')
