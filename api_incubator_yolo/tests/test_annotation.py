import unittest

import numpy as np

import app


class AnnotationColorTest(unittest.TestCase):
    def test_box_and_label_use_class_color(self):
        image = np.zeros((200, 400, 3), dtype=np.uint8)
        detections = [
            {'id': 1, 'box': [20, 50, 100, 100], 'class': 'retak', 'confidence': 0.9},
            {'id': 2, 'box': [220, 50, 100, 100], 'class': 'utuh', 'confidence': 0.9},
        ]
        output = app.gambar_anotasi(image, detections)
        for x, name in [(20, 'retak'), (220, 'utuh')]:
            box = output[100, x]
            label = output[48, x + 2]
            if name == 'retak':
                self.assertGreater(int(box[0]), int(box[1]))
                self.assertGreater(int(label[0]), int(label[1]))
            else:
                self.assertGreater(int(box[1]), int(box[0]))
                self.assertGreater(int(label[1]), int(label[0]))


if __name__ == '__main__':
    unittest.main()
